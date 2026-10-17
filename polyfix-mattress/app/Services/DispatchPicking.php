<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Libraries\Audit;
use App\Libraries\Identifiers;
use App\Libraries\RequestContext;
use App\Libraries\ScanCode;
use App\Libraries\Tx;
use CodeIgniter\Database\BaseConnection;

/**
 * Smart dispatch: building a consignment without typing a serial.
 *
 * The old way was to type every serial into a box. For a lorry holding
 * eighty mattresses that is eighty chances to transpose two digits, and the
 * requirement was to take it out of the normal workflow entirely. So a
 * dispatch is now picked, three ways, and all three write the same records:
 *
 *   by batch     open a batch, tick the units, add them. Tick-all, tick by
 *                model, tick by size — whatever matches how the stack is
 *                actually arranged.
 *   by scanning  point the camera at each label as it goes on the lorry.
 *                Continuous, and a label already on the list says so instead
 *                of being added twice.
 *   by hand      a single serial, for a torn label. Still there, no longer
 *                the way the job is done.
 *
 * A dispatch being picked is a DRAFT, and the DRAFT *is* the picking list:
 * every unit added moves to IN_DISPATCH immediately, so no two consignments
 * can hold the same mattress even if two operators pick at the same moment.
 * Confirming it is LogisticsService::sendDispatch(), which is what hands
 * custody to the dealer.
 *
 * Nothing here half-works. Adding forty units is one transaction: all forty
 * move and all forty are listed, or none are and the operator is told why.
 */
final class DispatchPicking
{
    /** One screenful of picking, bounded so a runaway form cannot move the whole plant. */
    public const MAX_PER_ADD = 500;

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
    // Starting and finishing
    // =========================================================================

    /**
     * Opens an empty consignment for a dealer.
     *
     * Empty on purpose: the units are picked onto it afterwards, which is the
     * whole point. It cannot be sent while empty — sendDispatch refuses that.
     *
     * @param array{warehouse_id:string, dealer_id:string} $input
     *
     * @return array{id:string, dispatch_code:string}
     */
    public function start(array $input): array
    {
        return Tx::run(function (BaseConnection $db) use ($input): array {
            $dealer = $db->table('dealers')->where(['id' => $input['dealer_id'], 'deleted_at' => null])->get()->getRowArray();
            if ($dealer === null) {
                throw AppException::notFound('dealer');
            }
            if ($dealer['status'] !== 'ACTIVE') {
                throw AppException::rule("{$dealer['business_name']} is not an active dealer, so stock cannot be sent to them.");
            }
            if ($db->table('warehouses')->where(['id' => $input['warehouse_id'], 'is_active' => 1])->countAllResults() === 0) {
                throw AppException::notFound('warehouse');
            }

            $id   = uuid4();
            $code = (new Identifiers($db))->dispatchCode();

            $db->table('dispatches')->insert([
                'id'            => $id,
                'dispatch_code' => $code,
                'warehouse_id'  => $input['warehouse_id'],
                'dealer_id'     => $input['dealer_id'],
                'status'        => 'DRAFT',
                'created_by'    => $this->context->userId(),
            ]);

            Audit::instance($db)->record('DISPATCH_STARTED', 'dispatch', $id, null, [
                'dispatchCode' => $code, 'dealerId' => $input['dealer_id'],
            ]);

            return ['id' => $id, 'dispatch_code' => $code];
        }, $this->db);
    }

    // =========================================================================
    // Picking: by batch, and in bulk
    // =========================================================================

    /**
     * Adds units to a draft consignment by their ids.
     *
     * This is what the tick boxes post, whether the operator ticked all of a
     * batch, everything of one size, or four units by hand — the screen
     * decides which boxes to tick, and they all arrive here.
     *
     * Units already on this consignment are not an error: a form submitted
     * twice, or a box ticked twice in two tabs, should end with the unit on
     * the list once and nobody told off. They are reported as skipped.
     *
     * @param list<string> $mattressIds
     *
     * @return array{added:int, skipped:int, total:int, dispatch_code:string}
     */
    public function addUnits(string $dispatchId, array $mattressIds): array
    {
        $ids = array_values(array_unique(array_filter($mattressIds, 'is_uuid')));

        if ($ids === []) {
            throw AppException::rule('Tick at least one mattress to add.');
        }
        if (count($ids) > self::MAX_PER_ADD) {
            throw AppException::rule('Add at most ' . self::MAX_PER_ADD . ' mattresses at a time.');
        }

        return Tx::run(function (BaseConnection $db) use ($dispatchId, $ids): array {
            $dispatch = $this->draftForUpdate($db, $dispatchId);

            // Locked for the rest of the transaction: a second operator
            // picking the same unit waits here and then finds it taken.
            $units = $db->query(
                'SELECT id, serial_number, current_status, current_warehouse_id FROM mattresses
                  WHERE id IN ? AND deleted_at IS NULL FOR UPDATE',
                [$ids],
            )->getResultArray();

            if (count($units) !== count($ids)) {
                throw AppException::rule('Some of those mattresses are no longer in stock records. Reload the list and try again.');
            }

            $already = array_column(
                $db->table('dispatch_items')->select('mattress_id')
                    ->where('dispatch_id', $dispatchId)->whereIn('mattress_id', $ids)->get()->getResultArray(),
                'mattress_id',
            );

            $toAdd   = [];
            $refused = [];

            foreach ($units as $unit) {
                if (in_array($unit['id'], $already, true)) {
                    continue;   // already on this list; nothing to do and nothing to complain about
                }
                if (! in_array($unit['current_status'], ['MANUFACTURED', 'RETURNED'], true)) {
                    $refused[] = $unit['serial_number'] . ' (' . strtolower(humanise($unit['current_status'])) . ')';

                    continue;
                }
                if ($unit['current_warehouse_id'] !== $dispatch['warehouse_id']) {
                    $refused[] = $unit['serial_number'] . ' (held at another plant)';

                    continue;
                }
                $toAdd[] = $unit;
            }

            // All or nothing. Adding most of a stack and silently leaving two
            // behind is how a lorry goes out short.
            if ($refused !== []) {
                throw AppException::rule(
                    count($refused) . ' mattress(es) cannot go on this consignment: '
                    . implode(', ', array_slice($refused, 0, 5))
                    . (count($refused) > 5 ? ' and ' . (count($refused) - 5) . ' more' : '')
                    . '. Nothing was added.',
                );
            }

            $this->attach($db, $dispatch, $toAdd);

            $total = $db->table('dispatch_items')->where('dispatch_id', $dispatchId)->countAllResults();

            if ($toAdd !== []) {
                Audit::instance($db)->record('DISPATCH_UNITS_ADDED', 'dispatch', $dispatchId, null, [
                    'dispatchCode' => $dispatch['dispatch_code'], 'added' => count($toAdd), 'total' => $total,
                ]);
            }

            return [
                'added'         => count($toAdd),
                'skipped'       => count($units) - count($toAdd),
                'total'         => $total,
                'dispatch_code' => $dispatch['dispatch_code'],
            ];
        }, $this->db);
    }

    /**
     * Takes units back off a draft consignment, returning them to free stock.
     *
     * A unit that came back from a dealer and was picked again returns to
     * MANUFACTURED rather than to RETURNED. That matches what cancelling a
     * whole consignment has always done, and both mean the same thing on the
     * floor: it is in the plant and can go out.
     *
     * @param list<string> $mattressIds
     *
     * @return array{removed:int, total:int, dispatch_code:string}
     */
    public function removeUnits(string $dispatchId, array $mattressIds): array
    {
        $ids = array_values(array_unique(array_filter($mattressIds, 'is_uuid')));
        if ($ids === []) {
            throw AppException::rule('Tick at least one mattress to take off.');
        }

        return Tx::run(function (BaseConnection $db) use ($dispatchId, $ids): array {
            $dispatch = $this->draftForUpdate($db, $dispatchId);

            $items = $db->table('dispatch_items di')->select('di.mattress_id, m.current_status')
                ->join('mattresses m', 'm.id = di.mattress_id')
                ->where('di.dispatch_id', $dispatchId)->whereIn('di.mattress_id', $ids)
                ->get()->getResultArray();

            foreach ($items as $item) {
                $this->lifecycle->transition(
                    $item['mattress_id'], $item['current_status'], 'MANUFACTURED', 'REMOVED_FROM_DISPATCH',
                    null, $this->context->userId(),
                    ['current_warehouse_id' => $dispatch['warehouse_id'], 'current_dealer_id' => null],
                    ['dispatchCode' => $dispatch['dispatch_code']],
                );
            }

            if ($items !== []) {
                $db->table('dispatch_items')->where('dispatch_id', $dispatchId)
                    ->whereIn('mattress_id', array_column($items, 'mattress_id'))->delete();

                // The scan record goes too, or rescanning the label would be
                // answered "already scanned" for a unit no longer on the list.
                $db->table('warehouse_scans')->where(['dispatch_id' => $dispatchId, 'purpose' => 'DISPATCH'])
                    ->whereIn('mattress_id', array_column($items, 'mattress_id'))->delete();

                Audit::instance($db)->record('DISPATCH_UNITS_REMOVED', 'dispatch', $dispatchId, null, [
                    'dispatchCode' => $dispatch['dispatch_code'], 'removed' => count($items),
                ]);
            }

            return [
                'removed'       => count($items),
                'total'         => $db->table('dispatch_items')->where('dispatch_id', $dispatchId)->countAllResults(),
                'dispatch_code' => $dispatch['dispatch_code'],
            ];
        }, $this->db);
    }

    // =========================================================================
    // Picking: by scanning
    // =========================================================================

    /**
     * One scan onto a draft consignment.
     *
     * Returns the scanner's contract rather than throwing: a label from
     * another plant, a label already on the list, a unit already sold
     * somewhere — all of these happen while loading a lorry, and the operator
     * needs to be told which, not shown an error page.
     *
     * @return array{ok:bool, status:string, title:string, message:string,
     *               detail?:array<string,string>, counter:array{done:int,total:int,label:string},
     *               mattress_id?:string}
     */
    public function scan(string $dispatchId, string $code, string $source = 'CAMERA'): array
    {
        $dispatch = $this->db->table('dispatches')
            ->where(['id' => $dispatchId, 'deleted_at' => null])->get()->getRowArray();

        if ($dispatch === null) {
            throw AppException::notFound('dispatch');
        }
        if ($dispatch['status'] !== 'DRAFT') {
            throw AppException::rule('This consignment has already been sent, so nothing more can be added to it.');
        }

        $source = $source === 'MANUAL' ? 'MANUAL' : 'CAMERA';
        $unit   = ScanCode::find($this->db, $code);

        if ($unit === null) {
            return $this->reply($dispatchId, false, 'UNKNOWN', 'Not recognised',
                'That code is not a ' . brand('shortName') . ' mattress label. Check the label, or type the serial.');
        }

        // Already on this list: the answer the floor asked for, word for word.
        $onList = $this->db->table('dispatch_items')
            ->where(['dispatch_id' => $dispatchId, 'mattress_id' => $unit['id']])->countAllResults() > 0;

        if ($onList) {
            return $this->reply($dispatchId, false, 'DUPLICATE', 'Already Scanned — No Duplicate Added',
                $unit['serial_number'] . ' is already on this consignment.', $unit);
        }

        if ($unit['current_status'] === 'IN_DISPATCH') {
            // On somebody else's list — the one case where "already scanned"
            // would be actively misleading.
            $other = $this->db->table('dispatch_items di')->select('d.dispatch_code')
                ->join('dispatches d', 'd.id = di.dispatch_id')
                ->where('di.mattress_id', $unit['id'])->where('d.deleted_at', null)
                ->get()->getRowArray();

            return $this->reply($dispatchId, false, 'REJECTED', 'On another consignment',
                $unit['serial_number'] . ' is being loaded onto '
                . ($other['dispatch_code'] ?? 'another consignment') . '. Take it off there first.', $unit);
        }

        if (! in_array($unit['current_status'], ['MANUFACTURED', 'RETURNED'], true)) {
            return $this->reply($dispatchId, false, 'REJECTED', 'Not available',
                $unit['serial_number'] . ' is recorded as ' . strtolower(humanise($unit['current_status']))
                . ', so it cannot be dispatched.', $unit);
        }

        if ($unit['current_warehouse_id'] !== $dispatch['warehouse_id']) {
            return $this->reply($dispatchId, false, 'REJECTED', 'Wrong plant',
                $unit['serial_number'] . ' is held at another plant and cannot go out from this one.', $unit);
        }

        return Tx::run(function (BaseConnection $db) use ($dispatchId, $dispatch, $unit, $source): array {
            // Re-read under a lock: between the checks above and here, another
            // operator may have taken this unit.
            $fresh = $db->query(
                'SELECT id, serial_number, current_status, current_warehouse_id FROM mattresses
                  WHERE id = ? AND deleted_at IS NULL FOR UPDATE',
                [$unit['id']],
            )->getRowArray();

            if ($fresh === null || ! in_array($fresh['current_status'], ['MANUFACTURED', 'RETURNED'], true)) {
                return $this->reply($dispatchId, false, 'REJECTED', 'Just taken',
                    $unit['serial_number'] . ' was picked by someone else a moment ago. Leave it where it is.', $unit);
            }

            $this->attach($db, $dispatch, [$fresh]);

            $db->query(
                'INSERT INTO warehouse_scans (id, mattress_id, batch_id, dispatch_id, purpose, scanned_by, scanned_at, source)
                 VALUES (?, ?, NULL, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE id = id',
                [uuid4(), $fresh['id'], $dispatchId, 'DISPATCH', $this->context->userId(), utc_now(), $source],
            );

            $reply = $this->reply($dispatchId, true, 'ACCEPTED', 'Added',
                $fresh['serial_number'] . ' on the consignment.', $unit);

            // So the picking screen can tick the box for this unit without
            // reloading the whole list.
            $reply['mattress_id'] = $fresh['id'];

            return $reply;
        }, $this->db);
    }

    // =========================================================================
    // Reading a consignment back
    // =========================================================================

    /**
     * A draft consignment and what is on it.
     *
     * @return array{dispatch:array<string,mixed>, items:list<array<string,mixed>>, counter:array<string,mixed>}
     */
    public function picking(string $dispatchId): array
    {
        $dispatch = $this->db->table('dispatches d')
            ->select('d.*, dl.business_name AS dealer, dl.city AS dealer_city, w.name AS warehouse')
            ->join('dealers dl', 'dl.id = d.dealer_id')
            ->join('warehouses w', 'w.id = d.warehouse_id')
            ->where(['d.id' => $dispatchId, 'd.deleted_at' => null])->get()->getRowArray();

        if ($dispatch === null) {
            throw AppException::notFound('dispatch');
        }

        return [
            'dispatch' => $dispatch,
            'items'    => $this->items($dispatchId),
            'counter'  => $this->counter($dispatchId),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function items(string $dispatchId): array
    {
        return $this->db->table('dispatch_items di')
            ->select('m.id, m.serial_number, v.size_label, p.name AS product_name, b.batch_code,'
                . ' di.added_at, s.source AS scanned_via')
            ->join('mattresses m', 'm.id = di.mattress_id')
            ->join('product_variants v', 'v.id = m.product_variant_id')
            ->join('products p', 'p.id = v.product_id')
            ->join('manufacturing_batches b', 'b.id = m.batch_id', 'left')
            ->join('warehouse_scans s', "s.mattress_id = m.id AND s.dispatch_id = di.dispatch_id AND s.purpose = 'DISPATCH'", 'left')
            ->where('di.dispatch_id', $dispatchId)
            ->orderBy('p.name')->orderBy('v.size_label')->orderBy('m.serial_number')
            ->get()->getResultArray();
    }

    /**
     * Free stock at a plant, as rows to tick.
     *
     * Grouped by batch so the screen matches the stack: a batch is what came
     * off the line together and is usually what is standing together.
     *
     * @return list<array<string,mixed>>
     */
    public function pickable(string $warehouseId, ?string $batchId = null, ?string $variantId = null): array
    {
        $builder = $this->db->table('mattresses m')
            ->select('m.id, m.serial_number, m.current_status, m.product_variant_id,'
                . ' v.size_label, p.name AS product_name, b.id AS batch_id, b.batch_code, b.manufactured_on')
            ->join('product_variants v', 'v.id = m.product_variant_id')
            ->join('products p', 'p.id = v.product_id')
            ->join('manufacturing_batches b', 'b.id = m.batch_id', 'left')
            ->whereIn('m.current_status', ['MANUFACTURED', 'RETURNED'])
            ->where('m.current_warehouse_id', $warehouseId)
            ->where('m.deleted_at', null)
            ->orderBy('b.manufactured_on', 'DESC')->orderBy('p.name')->orderBy('v.size_label')->orderBy('m.serial_number')
            ->limit(self::MAX_PER_ADD);

        if ($batchId !== null) {
            $builder->where('m.batch_id', $batchId);
        }
        if ($variantId !== null) {
            $builder->where('m.product_variant_id', $variantId);
        }

        return $builder->get()->getResultArray();
    }

    /**
     * Batches that still hold free stock at a plant, with how much.
     *
     * @return list<array<string,mixed>>
     */
    public function batchesWithStock(string $warehouseId): array
    {
        return $this->db->query(
            "SELECT b.id, b.batch_code, b.manufactured_on, COUNT(*) AS available
               FROM mattresses m
               JOIN manufacturing_batches b ON b.id = m.batch_id
              WHERE m.current_warehouse_id = ?
                AND m.current_status IN ('MANUFACTURED', 'RETURNED')
                AND m.deleted_at IS NULL
              GROUP BY b.id, b.batch_code, b.manufactured_on
              ORDER BY b.manufactured_on DESC",
            [$warehouseId],
        )->getResultArray();
    }

    /** Draft consignments being picked right now. @return list<array<string,mixed>> */
    public function openDrafts(): array
    {
        return $this->db->table('dispatches d')
            ->select('d.id, d.dispatch_code, d.created_at, dl.business_name AS dealer, w.name AS warehouse,'
                . ' (SELECT COUNT(*) FROM dispatch_items di WHERE di.dispatch_id = d.id) AS items', false)
            ->join('dealers dl', 'dl.id = d.dealer_id')
            ->join('warehouses w', 'w.id = d.warehouse_id')
            ->where(['d.status' => 'DRAFT', 'd.deleted_at' => null])
            ->orderBy('d.created_at', 'DESC')
            ->get()->getResultArray();
    }

    /** Consignments that have gone out. @return list<array<string,mixed>> */
    public function history(int $limit = 40): array
    {
        return $this->db->table('dispatches d')
            ->select('d.id, d.dispatch_code, d.status, d.dispatched_at, d.transporter, d.lr_number,'
                . ' dl.business_name AS dealer, dl.city AS dealer_city,'
                . ' (SELECT COUNT(*) FROM dispatch_items di WHERE di.dispatch_id = d.id) AS items', false)
            ->join('dealers dl', 'dl.id = d.dealer_id')
            ->whereIn('d.status', ['DISPATCHED', 'PARTIALLY_RECEIVED', 'RECEIVED'])
            ->where('d.deleted_at', null)
            ->orderBy('d.dispatched_at', 'DESC')
            ->limit($limit)->get()->getResultArray();
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * Writes the dispatch_items rows and moves each unit to IN_DISPATCH.
     *
     * The unique key on (dispatch_id, mattress_id) is the backstop, and the
     * lifecycle transition is checked against the status we read under the
     * lock, so a unit taken in the meantime makes the whole add fail rather
     * than quietly moving twice.
     *
     * @param array<string,mixed>              $dispatch
     * @param list<array<string,mixed>>        $units
     */
    private function attach(BaseConnection $db, array $dispatch, array $units): void
    {
        if ($units === []) {
            return;
        }

        $now = utc_now();
        $db->table('dispatch_items')->insertBatch(array_map(static fn ($unit) => [
            'id' => uuid4(), 'dispatch_id' => $dispatch['id'], 'mattress_id' => $unit['id'], 'added_at' => $now,
        ], $units));

        foreach ($units as $unit) {
            $this->lifecycle->transition(
                $unit['id'], $unit['current_status'], 'IN_DISPATCH', 'ADDED_TO_DISPATCH',
                $dispatch['dealer_id'], $this->context->userId(), [],
                ['dispatchCode' => $dispatch['dispatch_code']],
            );
        }
    }

    /** @return array<string,mixed> */
    private function draftForUpdate(BaseConnection $db, string $dispatchId): array
    {
        $dispatch = $db->query(
            'SELECT * FROM dispatches WHERE id = ? AND deleted_at IS NULL FOR UPDATE',
            [$dispatchId],
        )->getRowArray();

        if ($dispatch === null) {
            throw AppException::notFound('dispatch');
        }
        if ($dispatch['status'] !== 'DRAFT') {
            throw AppException::rule('This consignment has already been sent (' . strtolower(humanise($dispatch['status'])) . ').');
        }

        return $dispatch;
    }

    /**
     * @param array<string,mixed>|null $unit
     *
     * @return array{ok:bool, status:string, title:string, message:string,
     *               detail?:array<string,string>, counter:array{done:int,total:int,label:string}}
     */
    private function reply(string $dispatchId, bool $ok, string $status, string $title, string $message, ?array $unit = null): array
    {
        $reply = [
            'ok'      => $ok,
            'status'  => $status,
            'title'   => $title,
            'message' => $message,
            'counter' => $this->counter($dispatchId),
        ];

        if ($unit !== null) {
            $reply['detail'] = array_filter([
                'model' => $unit['product_name'] ?? null,
                'size'  => $unit['size_label'] ?? null,
                'batch' => $unit['batch_code'] ?? null,
            ]);
        }

        return $reply;
    }

    /**
     * How many are on the consignment.
     *
     * There is no target to count towards — a lorry holds what it holds — so
     * the label is a plain total rather than "x of y".
     *
     * @return array{done:int, total:int, label:string}
     */
    private function counter(string $dispatchId): array
    {
        $count = $this->db->table('dispatch_items')->where('dispatch_id', $dispatchId)->countAllResults();

        return [
            'done'  => $count,
            'total' => $count,
            'label' => $count . ($count === 1 ? ' Mattress Loaded' : ' Mattresses Loaded'),
        ];
    }
}
