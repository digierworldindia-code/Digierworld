<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Libraries\Audit;
use App\Libraries\DealerScope;
use App\Libraries\Identifiers;
use App\Libraries\RequestContext;
use App\Libraries\Tx;
use CodeIgniter\Database\BaseConnection;

/**
 * Manufacture, dispatch and dealer receipt: the first half of a mattress's life.
 *
 *   batch ──produce──▶ MANUFACTURED ──dispatch──▶ IN_DISPATCH ──send──▶ DISPATCHED
 *                                                                  │
 *                                          dealer confirms receipt ▼
 *                                                           DEALER_RECEIVED
 *
 * Each operation is one transaction: every unit moves, every passport event is
 * written and the audit entry is sealed, or nothing happens at all.
 */
final class LogisticsService
{
    public const MAX_UNITS_PER_RUN = 2000;

    private Lifecycle $lifecycle;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly RequestContext $context,
    ) {
        $this->lifecycle = new Lifecycle($db);
    }

    public static function instance(): self
    {
        return new self(db_connect(), service('requestContext'));
    }

    // =========================================================================
    // Manufacturing
    // =========================================================================

    /** @param array{warehouse_id:string, manufactured_on:string, planned_quantity:int, line_supervisor?:?string, quality_checked_by?:?string, notes?:?string} $input */
    public function createBatch(array $input): array
    {
        return Tx::run(function (BaseConnection $db) use ($input): array {
            $warehouse = $db->table('warehouses')->where(['id' => $input['warehouse_id'], 'is_active' => 1])->get()->getRow();
            if ($warehouse === null) {
                throw AppException::notFound('warehouse');
            }

            $id   = uuid4();
            $code = (new Identifiers($db))->batchCode();
            $db->table('manufacturing_batches')->insert([
                'id'                 => $id,
                'batch_code'         => $code,
                'warehouse_id'       => $input['warehouse_id'],
                'manufactured_on'    => $input['manufactured_on'],
                'planned_quantity'   => (int) $input['planned_quantity'],
                'produced_quantity'  => 0,
                'line_supervisor'    => $input['line_supervisor'] ?? null,
                'quality_checked_by' => $input['quality_checked_by'] ?? null,
                'notes'              => $input['notes'] ?? null,
                'created_by'         => $this->context->userId(),
            ]);

            Audit::instance($db)->record('BATCH_CREATED', 'manufacturing_batch', $id, null, [
                'batchCode' => $code, 'plannedQuantity' => (int) $input['planned_quantity'],
            ]);

            return ['id' => $id, 'batch_code' => $code];
        }, $this->db);
    }

    /**
     * Issues serial numbers and QR tokens for new units in a batch.
     *
     * @param list<array{product_variant_id:string, quantity:int}> $items
     *
     * @return array{batch_code:string, first_serial:string, last_serial:string, count:int}
     */
    public function produce(string $batchId, array $items): array
    {
        $items = array_values(array_filter($items, static fn ($i) => (int) ($i['quantity'] ?? 0) > 0));
        $total = array_sum(array_map(static fn ($i) => (int) $i['quantity'], $items));

        if ($total === 0) {
            throw AppException::rule('Enter a quantity for at least one size.');
        }
        if ($total > self::MAX_UNITS_PER_RUN) {
            throw AppException::rule('Produce at most ' . self::MAX_UNITS_PER_RUN . ' units in a single operation.');
        }

        return Tx::run(function (BaseConnection $db) use ($batchId, $items, $total): array {
            $batch = $db->table('manufacturing_batches')->where('id', $batchId)->get()->getRowArray();
            if ($batch === null) {
                throw AppException::notFound('manufacturing batch');
            }

            // Variants are resolved before any serial is issued, so a bad id
            // cannot burn serial numbers.
            $variantIds = array_values(array_unique(array_column($items, 'product_variant_id')));
            $found      = $db->table('product_variants')->whereIn('id', $variantIds)->where('deleted_at', null)->countAllResults();
            if ($found !== count($variantIds)) {
                throw AppException::notFound('product size', 'one or more variant ids did not resolve');
            }

            $serials        = (new Identifiers($db))->serials($total);
            $manufacturedAt = $batch['manufactured_on'] . ' 00:00:00.000000';
            $actor          = $this->context->userId();
            $cursor         = 0;
            $rows           = [];

            foreach ($items as $item) {
                for ($n = 0; $n < (int) $item['quantity']; $n++) {
                    $rows[] = [
                        'id'                   => uuid4(),
                        'serial_number'        => $serials[$cursor++],
                        'qr_token'             => Identifiers::qrToken(),
                        'product_variant_id'   => $item['product_variant_id'],
                        'batch_id'             => $batch['id'],
                        'current_status'       => 'MANUFACTURED',
                        'current_warehouse_id' => $batch['warehouse_id'],
                        'manufactured_at'      => $manufacturedAt,
                        'is_replacement'       => 0,
                        'created_by'           => $actor,
                    ];
                }
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                $db->table('mattresses')->insertBatch($chunk);
            }
            foreach ($rows as $row) {
                $this->lifecycle->event($row['id'], 'MANUFACTURED', null, 'MANUFACTURED', null, $actor, ['batchCode' => $batch['batch_code']]);
            }

            $db->table('manufacturing_batches')->where('id', $batch['id'])
                ->set('produced_quantity', 'produced_quantity + ' . $total, false)->update();

            $first = $rows[0]['serial_number'];
            $last  = $rows[count($rows) - 1]['serial_number'];
            Audit::instance($db)->record('MATTRESS_PRODUCED', 'manufacturing_batch', $batch['id'], null, [
                'batchCode' => $batch['batch_code'], 'quantity' => $total, 'firstSerial' => $first, 'lastSerial' => $last,
            ]);

            return ['batch_code' => $batch['batch_code'], 'first_serial' => $first, 'last_serial' => $last, 'count' => $total];
        }, $this->db);
    }

    // =========================================================================
    // Dispatch
    // =========================================================================

    /**
     * A dispatch starts as DRAFT (shown to staff as "Ready"). The units are
     * reserved immediately so they cannot be put on a second dispatch.
     *
     * @param array{warehouse_id:string, dealer_id:string, serials:list<string>, expected_at?:?string,
     *              transporter?:?string, lr_number?:?string, invoice_number?:?string, vehicle_number?:?string, remarks?:?string} $input
     */
    public function createDispatch(array $input): array
    {
        $serials = array_values(array_filter(array_map('trim', $input['serials'])));
        if ($serials === []) {
            throw AppException::rule('Add at least one serial number to the dispatch.');
        }
        if (count(array_unique($serials)) !== count($serials)) {
            throw AppException::rule('The same serial number appears more than once in this dispatch.');
        }

        return Tx::run(function (BaseConnection $db) use ($input, $serials): array {
            $dealer = $db->table('dealers')->where(['id' => $input['dealer_id'], 'deleted_at' => null])->get()->getRowArray();
            if ($dealer === null) {
                throw AppException::notFound('dealer');
            }
            if ($dealer['status'] !== 'ACTIVE') {
                throw AppException::rule("{$dealer['business_name']} is not an active dealer, so stock cannot be dispatched to them.");
            }
            if ($db->table('warehouses')->where(['id' => $input['warehouse_id'], 'is_active' => 1])->countAllResults() === 0) {
                throw AppException::notFound('warehouse');
            }

            // Locked for the rest of the transaction: no concurrent dispatch can take them.
            $mattresses = $db->query(
                'SELECT id, serial_number, current_status, current_warehouse_id FROM mattresses
                  WHERE serial_number IN ? AND deleted_at IS NULL FOR UPDATE',
                [$serials],
            )->getResultArray();

            $foundSerials = array_column($mattresses, 'serial_number');
            $missing      = array_values(array_diff($serials, $foundSerials));
            if ($missing !== []) {
                throw AppException::rule(count($missing) . ' serial number(s) are not in stock records: ' . $this->sample($missing));
            }

            $unavailable = array_filter($mattresses, static fn ($m) => ! in_array($m['current_status'], ['MANUFACTURED', 'RETURNED'], true));
            if ($unavailable !== []) {
                throw AppException::rule(count($unavailable) . ' unit(s) are not available to dispatch: ' . $this->sample(
                    array_map(static fn ($m) => $m['serial_number'] . ' (' . strtolower(humanise($m['current_status'])) . ')', $unavailable),
                ));
            }

            $elsewhere = array_filter($mattresses, static fn ($m) => $m['current_warehouse_id'] !== $input['warehouse_id']);
            if ($elsewhere !== []) {
                throw AppException::rule(count($elsewhere) . ' unit(s) are held at a different warehouse and cannot be dispatched from this one.');
            }

            $id    = uuid4();
            $code  = (new Identifiers($db))->dispatchCode();
            $actor = $this->context->userId();

            $db->table('dispatches')->insert([
                'id'             => $id,
                'dispatch_code'  => $code,
                'warehouse_id'   => $input['warehouse_id'],
                'dealer_id'      => $input['dealer_id'],
                'status'         => 'DRAFT',
                'expected_at'    => ($input['expected_at'] ?? '') !== '' ? $input['expected_at'] . ' 00:00:00.000000' : null,
                'transporter'    => $input['transporter'] ?? null,
                'lr_number'      => $input['lr_number'] ?? null,
                'invoice_number' => $input['invoice_number'] ?? null,
                'vehicle_number' => $input['vehicle_number'] ?? null,
                'remarks'        => $input['remarks'] ?? null,
                'created_by'     => $actor,
            ]);

            $now = utc_now();
            $db->table('dispatch_items')->insertBatch(array_map(static fn ($m) => [
                'id' => uuid4(), 'dispatch_id' => $id, 'mattress_id' => $m['id'], 'added_at' => $now,
            ], $mattresses));

            foreach ($mattresses as $m) {
                $this->lifecycle->transition($m['id'], $m['current_status'], 'IN_DISPATCH', 'ADDED_TO_DISPATCH',
                    $input['dealer_id'], $actor, [], ['dispatchCode' => $code]);
            }

            Audit::instance($db)->record('DISPATCH_CREATED', 'dispatch', $id, null, [
                'dispatchCode' => $code, 'dealerId' => $input['dealer_id'], 'itemCount' => count($mattresses),
            ]);

            return ['id' => $id, 'dispatch_code' => $code, 'item_count' => count($mattresses)];
        }, $this->db);
    }

    /**
     * READY → DISPATCHED. Custody passes to the dealer at this moment, which is
     * also what lets the dealer see the consignment coming.
     *
     * @param array{dispatched_at?:?string, transporter?:?string, lr_number?:?string, vehicle_number?:?string, invoice_number?:?string} $input
     */
    public function sendDispatch(string $dispatchId, array $input = []): array
    {
        return Tx::run(function (BaseConnection $db) use ($dispatchId, $input): array {
            $dispatch = $db->query('SELECT * FROM dispatches WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$dispatchId])->getRowArray();
            if ($dispatch === null) {
                throw AppException::notFound('dispatch');
            }
            if ($dispatch['status'] !== 'DRAFT') {
                throw AppException::rule('This dispatch has already been sent (' . strtolower(humanise($dispatch['status'])) . ').');
            }

            $items = $db->table('dispatch_items di')->select('m.id, m.current_status')
                ->join('mattresses m', 'm.id = di.mattress_id')->where('di.dispatch_id', $dispatchId)->get()->getResultArray();
            if ($items === []) {
                throw AppException::rule('Add at least one mattress before sending this dispatch.');
            }

            // The exact moment of dispatch is recorded, to the second.
            $dispatchedAt = ($input['dispatched_at'] ?? '') !== ''
                ? gmdate('Y-m-d H:i:s', strtotime($input['dispatched_at'] . ' Asia/Kolkata')) . '.000000'
                : utc_now();
            $keep = static fn (string $k) => ($input[$k] ?? '') !== '' ? $input[$k] : $dispatch[$k];

            $db->table('dispatches')->where('id', $dispatchId)->update([
                'status'         => 'DISPATCHED',
                'dispatched_at'  => $dispatchedAt,
                'transporter'    => $keep('transporter'),
                'lr_number'      => $keep('lr_number'),
                'vehicle_number' => $keep('vehicle_number'),
                'invoice_number' => $keep('invoice_number'),
                'updated_by'     => $this->context->userId(),
            ]);

            foreach ($items as $item) {
                $this->lifecycle->transition($item['id'], $item['current_status'], 'DISPATCHED', 'DISPATCHED',
                    $dispatch['dealer_id'], $this->context->userId(),
                    ['dispatched_at' => $dispatchedAt, 'current_dealer_id' => $dispatch['dealer_id']],
                    ['dispatchCode' => $dispatch['dispatch_code'], 'transporter' => $keep('transporter')]);
            }

            Audit::instance($db)->record('DISPATCH_SENT', 'dispatch', $dispatchId, ['status' => 'DRAFT'], [
                'status' => 'DISPATCHED', 'dispatchedAt' => $dispatchedAt, 'itemCount' => count($items),
            ]);

            return ['dispatch_code' => $dispatch['dispatch_code'], 'item_count' => count($items)];
        }, $this->db);
    }

    /** Only a dispatch that has not left can be cancelled; its units return to free stock. */
    public function cancelDispatch(string $dispatchId, string $reason): array
    {
        if (trim($reason) === '') {
            throw AppException::rule('Give a reason for cancelling this dispatch.');
        }

        return Tx::run(function (BaseConnection $db) use ($dispatchId, $reason): array {
            $dispatch = $db->query('SELECT * FROM dispatches WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$dispatchId])->getRowArray();
            if ($dispatch === null) {
                throw AppException::notFound('dispatch');
            }
            if ($dispatch['status'] !== 'DRAFT') {
                throw AppException::rule('Only a dispatch that has not been sent can be cancelled. Once sent, record a return instead.');
            }

            $items = $db->table('dispatch_items')->select('mattress_id')->where('dispatch_id', $dispatchId)->get()->getResultArray();
            foreach ($items as $item) {
                $this->lifecycle->transition($item['mattress_id'], 'IN_DISPATCH', 'MANUFACTURED', 'DISPATCH_CANCELLED',
                    null, $this->context->userId(), ['current_warehouse_id' => $dispatch['warehouse_id']],
                    ['dispatchCode' => $dispatch['dispatch_code'], 'reason' => $reason]);
            }

            $db->table('dispatches')->where('id', $dispatchId)->update([
                'status' => 'CANCELLED', 'deleted_at' => utc_now(), 'deleted_by' => $this->context->userId(), 'delete_reason' => $reason,
            ]);

            Audit::instance($db)->record('DISPATCH_CANCELLED', 'dispatch', $dispatchId,
                ['status' => 'DRAFT', 'itemCount' => count($items)], ['status' => 'CANCELLED'], $reason);

            return ['dispatch_code' => $dispatch['dispatch_code'], 'released' => count($items)];
        }, $this->db);
    }

    // =========================================================================
    // Dealer receipt
    // =========================================================================

    /**
     * The dealer confirms what physically arrived, unit by unit.
     *
     * @param list<array{serial:string, condition:'OK'|'DAMAGED'|'MISSING', remarks?:?string}> $lines
     */
    public function receive(string $dispatchId, array $lines, ?string $remarks = null): array
    {
        $scope = DealerScope::current();
        if ($lines === []) {
            throw AppException::rule('Scan or tick at least one mattress to confirm.');
        }

        return Tx::run(function (BaseConnection $db) use ($scope, $dispatchId, $lines, $remarks): array {
            $builder  = $db->table('dispatches')->where('id', $dispatchId)->where('deleted_at', null);
            $dispatch = $scope->apply($builder, 'dispatches')->get()->getRowArray();
            if ($dispatch === null) {
                throw AppException::notFound('consignment');
            }
            if (! in_array($dispatch['status'], ['DISPATCHED', 'PARTIALLY_RECEIVED'], true)) {
                throw AppException::rule('This consignment is not awaiting receipt.');
            }

            $units = $db->query(
                'SELECT m.id, m.serial_number, m.current_status FROM dispatch_items di
                   JOIN mattresses m ON m.id = di.mattress_id WHERE di.dispatch_id = ? FOR UPDATE',
                [$dispatchId],
            )->getResultArray();
            $bySerial = array_column($units, null, 'serial_number');

            $unknown = array_values(array_filter(array_column($lines, 'serial'), static fn ($s) => ! isset($bySerial[$s])));
            if ($unknown !== []) {
                throw AppException::rule(count($unknown) . ' scanned unit(s) are not part of this consignment: ' . $this->sample($unknown));
            }

            // A unit already accounted for on an earlier receipt is not counted twice.
            $accountedBefore = array_column($db->table('dealer_receipt_items ri')->select('ri.mattress_id')
                ->join('dealer_receipts r', 'r.id = ri.receipt_id')->where('r.dispatch_id', $dispatchId)->get()->getResultArray(), 'mattress_id');

            $receiptId = uuid4();
            $now       = utc_now();
            $actor     = $this->context->userId();
            $db->table('dealer_receipts')->insert([
                'id' => $receiptId, 'dispatch_id' => $dispatchId, 'dealer_id' => $scope->dealerId,
                'received_by_user_id' => $actor, 'received_at' => $now, 'remarks' => $remarks, 'created_at' => $now,
            ]);

            $received = $damaged = $missing = 0;
            foreach ($lines as $line) {
                $unit = $bySerial[$line['serial']];
                if (in_array($unit['id'], $accountedBefore, true)) {
                    continue;
                }
                $condition = in_array($line['condition'], ['OK', 'DAMAGED', 'MISSING'], true) ? $line['condition'] : 'OK';
                $note      = ($line['remarks'] ?? '') !== '' ? $line['remarks'] : null;

                $db->table('dealer_receipt_items')->insert([
                    'id' => uuid4(), 'receipt_id' => $receiptId, 'mattress_id' => $unit['id'], 'condition' => $condition, 'remarks' => $note,
                ]);

                if ($condition === 'MISSING') {
                    $missing++;
                    $this->lifecycle->event($unit['id'], 'RECEIPT_MISSING', $unit['current_status'], null, $scope->dealerId, $actor,
                        ['dispatchCode' => $dispatch['dispatch_code'], 'remarks' => $note]);

                    continue;
                }

                $changes = ['received_at' => $now, 'current_dealer_id' => $scope->dealerId, 'current_warehouse_id' => null];
                if ($condition === 'DAMAGED') {
                    $changes['grade_note'] = mb_substr('Received damaged: ' . ($note ?? 'no detail given'), 0, 200);
                    $damaged++;
                }
                $this->lifecycle->transition($unit['id'], $unit['current_status'], 'DEALER_RECEIVED',
                    $condition === 'DAMAGED' ? 'RECEIVED_DAMAGED' : 'DEALER_RECEIVED', $scope->dealerId, $actor, $changes,
                    ['dispatchCode' => $dispatch['dispatch_code'], 'condition' => $condition]);
                $received++;
            }

            // Complete only when every unit on the dispatch is accounted for.
            $accounted = $db->table('dealer_receipt_items ri')->join('dealer_receipts r', 'r.id = ri.receipt_id')
                ->where('r.dispatch_id', $dispatchId)->countAllResults();
            $status = $accounted >= count($units) ? 'RECEIVED' : 'PARTIALLY_RECEIVED';
            $db->table('dispatches')->where('id', $dispatchId)->update(['status' => $status]);

            Audit::instance($db)->record('DISPATCH_RECEIVED', 'dispatch', $dispatchId, null, [
                'dispatchCode' => $dispatch['dispatch_code'], 'received' => $received, 'damaged' => $damaged,
                'missing' => $missing, 'dispatchStatus' => $status,
            ]);

            if ($damaged + $missing > 0) {
                $db->table('notifications')->insert([
                    'id' => uuid4(), 'dealer_id' => null, 'channel' => 'IN_APP', 'type' => 'RECEIPT_EXCEPTION',
                    'title' => "Receipt exceptions on {$dispatch['dispatch_code']}",
                    'body'  => "{$damaged} damaged and {$missing} missing unit(s) reported at receipt.",
                    'entity' => 'dispatch', 'entity_id' => $dispatchId, 'created_at' => $now,
                ]);
            }

            return ['receipt_id' => $receiptId, 'received' => $received, 'damaged' => $damaged, 'missing' => $missing, 'status' => $status];
        }, $this->db);
    }

    /** @param list<string> $items */
    private function sample(array $items): string
    {
        return implode(', ', array_slice($items, 0, 5)) . (count($items) > 5 ? '…' : '');
    }
}
