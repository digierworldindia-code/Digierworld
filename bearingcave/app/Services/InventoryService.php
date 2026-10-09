<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Models\InventoryLotModel;
use App\Models\InventoryMovementModel;

/**
 * Stock ledger. All quantity changes go through here and are recorded as
 * inventory_movements. Reservations use a single conditional UPDATE so two
 * concurrent orders can never reserve the same units (InnoDB row lock +
 * predicate re-check), and a CHECK constraint backs this up in the schema.
 *
 * Inventory age buckets (based on the oldest lot that still has stock):
 *   lt_6m  : received less than 6 months ago            (received_on >  today - 6 months)
 *   6_12m  : 6 to 12 months old, both bounds inclusive   (today - 12 months <= received_on <= today - 6 months)
 *   gt_12m : more than 12 months old                     (received_on <  today - 12 months)
 */
class InventoryService
{
    public const AGE_BUCKETS = [
        'lt_6m'  => 'Less than 6 months',
        '6_12m'  => '6–12 months',
        'gt_12m' => 'More than 12 months',
    ];

    public static function ageBucket(?string $date, ?string $today = null): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        $t      = new \DateTimeImmutable($today ?? 'today');
        $d      = new \DateTimeImmutable($date);
        $six    = $t->modify('-6 months');
        $twelve = $t->modify('-12 months');
        if ($d > $six) {
            return 'lt_6m';
        }
        if ($d >= $twelve) {
            return '6_12m';
        }

        return 'gt_12m';
    }

    /**
     * SQL condition for an age bucket on a DATE column.
     */
    public static function ageBucketSql(string $bucket, string $column): ?string
    {
        $six    = db_connect()->escape(date('Y-m-d', strtotime('-6 months')));
        $twelve = db_connect()->escape(date('Y-m-d', strtotime('-12 months')));

        return match ($bucket) {
            'lt_6m'  => "{$column} > {$six}",
            '6_12m'  => "{$column} BETWEEN {$twelve} AND {$six}",
            'gt_12m' => "{$column} < {$twelve}",
            default  => null,
        };
    }

    public function receive(int $productId, int $qty, string $receivedOn, ?string $lotCode = null, ?string $location = null, string $type = 'receipt', ?string $note = null, ?string $manufacturedOn = null): int
    {
        if ($qty <= 0) {
            throw new BusinessRuleException('Received quantity must be greater than zero.');
        }
        $db = db_connect();
        $db->transBegin();

        try {
            $lotCode = $lotCode ?: 'LOT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $lotId   = (int) model(InventoryLotModel::class)->insert([
                'product_id'         => $productId,
                'lot_code'           => $lotCode,
                'quantity'           => $qty,
                'received_on'        => $receivedOn,
                'manufactured_on'    => $manufacturedOn,
                'warehouse_location' => $location,
            ]);
            $db->query('UPDATE products SET stock_on_hand = stock_on_hand + ? WHERE id = ?', [$qty, $productId]);
            $this->refreshAgeDate($productId);
            $this->record($productId, $lotId, $type, $qty, 'inventory_lot', $lotId, $note);

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        return $lotId;
    }

    /**
     * Adjusts a lot's quantity by a signed delta (stock count corrections).
     * Cannot reduce available stock below what is already reserved.
     */
    public function adjust(int $productId, int $lotId, int $delta, string $note): void
    {
        if ($delta === 0) {
            return;
        }
        if (trim($note) === '') {
            throw new BusinessRuleException('Please give a reason for the stock adjustment.');
        }
        $db = db_connect();
        $db->transBegin();

        try {
            $db->query('UPDATE inventory_lots SET quantity = quantity + ? WHERE id = ? AND product_id = ? AND quantity + ? >= 0', [$delta, $lotId, $productId, $delta]);
            if ($db->affectedRows() !== 1) {
                throw new BusinessRuleException('The lot does not have enough quantity for this adjustment.');
            }
            $db->query('UPDATE products SET stock_on_hand = stock_on_hand + ? WHERE id = ? AND stock_on_hand + ? >= stock_reserved', [$delta, $productId, $delta]);
            if ($db->affectedRows() !== 1) {
                throw new BusinessRuleException('Stock cannot go below the quantity already reserved for orders.');
            }
            $this->refreshAgeDate($productId);
            $this->record($productId, $lotId, 'adjustment', $delta, 'inventory_lot', $lotId, $note);
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }
    }

    /**
     * Atomically reserves stock. Throws if not enough is available.
     * Must be called inside the caller's transaction when part of a larger unit of work.
     */
    public function reserve(int $productId, int $qty, string $refType, int $refId): void
    {
        if ($qty <= 0) {
            throw new BusinessRuleException('Quantity must be greater than zero.');
        }
        $db = db_connect();
        $db->query(
            'UPDATE products SET stock_reserved = stock_reserved + ? WHERE id = ? AND deleted_at IS NULL AND (stock_on_hand - stock_reserved) >= ?',
            [$qty, $productId, $qty]
        );
        if ($db->affectedRows() !== 1) {
            $p = $db->table('products')->select('name, stock_on_hand, stock_reserved')->where('id', $productId)->get()->getRowArray();
            $available = $p ? max(0, (int) $p['stock_on_hand'] - (int) $p['stock_reserved']) : 0;

            throw new InsufficientStockException(sprintf(
                'Only %d unit(s) of "%s" are available; %d requested.',
                $available,
                $p['name'] ?? 'this product',
                $qty
            ));
        }
        $this->record($productId, null, 'reservation', $qty, $refType, $refId, null);
    }

    public function release(int $productId, int $qty, string $refType, int $refId, ?string $note = null): void
    {
        $db = db_connect();
        $db->query('UPDATE products SET stock_reserved = stock_reserved - ? WHERE id = ? AND stock_reserved >= ?', [$qty, $productId, $qty]);
        if ($db->affectedRows() !== 1) {
            throw new BusinessRuleException('Reserved stock is lower than the quantity being released.');
        }
        $this->record($productId, null, 'release', -$qty, $refType, $refId, $note);
    }

    /**
     * Converts a reservation into a physical dispatch, consuming lots FIFO
     * (oldest stock first).
     */
    public function dispatch(int $productId, int $qty, string $refType, int $refId): void
    {
        $db = db_connect();
        $db->query(
            'UPDATE products SET stock_reserved = stock_reserved - ?, stock_on_hand = stock_on_hand - ? WHERE id = ? AND stock_reserved >= ? AND stock_on_hand >= ?',
            [$qty, $qty, $productId, $qty, $qty]
        );
        if ($db->affectedRows() !== 1) {
            throw new BusinessRuleException('Cannot dispatch more than the reserved quantity.');
        }

        $remaining = $qty;
        $lots      = $db->query('SELECT id, quantity FROM inventory_lots WHERE product_id = ? AND quantity > 0 ORDER BY received_on ASC, id ASC FOR UPDATE', [$productId])->getResultArray();
        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, (int) $lot['quantity']);
            $db->query('UPDATE inventory_lots SET quantity = quantity - ? WHERE id = ?', [$take, $lot['id']]);
            $this->record($productId, (int) $lot['id'], 'dispatch', -$take, $refType, $refId, null);
            $remaining -= $take;
        }
        if ($remaining > 0) {
            throw new BusinessRuleException('Lot quantities are inconsistent with product stock; dispatch aborted.');
        }
        $this->refreshAgeDate($productId);
    }

    public function available(array $product): int
    {
        return max(0, (int) $product['stock_on_hand'] - (int) $product['stock_reserved']);
    }

    public function refreshAgeDate(int $productId): void
    {
        db_connect()->query(
            'UPDATE products SET inventory_age_date = (SELECT MIN(received_on) FROM inventory_lots WHERE product_id = ? AND quantity > 0) WHERE id = ?',
            [$productId, $productId]
        );
    }

    private function record(int $productId, ?int $lotId, string $type, int $qty, ?string $refType, ?int $refId, ?string $note): void
    {
        $p = db_connect()->table('products')->select('stock_on_hand, stock_reserved')->where('id', $productId)->get()->getRowArray();
        model(InventoryMovementModel::class)->insert([
            'product_id'     => $productId,
            'lot_id'         => $lotId,
            'movement_type'  => $type,
            'quantity'       => $qty,
            'on_hand_after'  => (int) ($p['stock_on_hand'] ?? 0),
            'reserved_after' => (int) ($p['stock_reserved'] ?? 0),
            'reference_type' => $refType,
            'reference_id'   => $refId,
            'user_id'        => auth()->loggedIn() ? (int) auth()->id() : null,
            'note'           => $note ? mb_substr($note, 0, 255) : null,
        ]);
    }
}
