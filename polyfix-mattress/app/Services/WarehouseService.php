<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Libraries\Audit;
use App\Libraries\RequestContext;
use App\Libraries\ScanCode;
use App\Libraries\Tx;
use CodeIgniter\Database\BaseConnection;

/**
 * The warehouse panel's work: opening a batch, counting units into it by
 * scanning, and reading stock back.
 *
 * The shape of the job on the floor decided the shape of this class.
 *
 * Opening a batch issues every serial and QR token up front, in one
 * transaction, so a run of a hundred mattresses needs one form and no typing.
 * The labels are then printed and applied on the line.
 *
 * Scanning afterwards does not create anything. It confirms that a unit which
 * the system believes was made has physically been made, and counts it once.
 * Separating the two is what lets the count mean something: "87 of 100" is a
 * statement about mattresses that exist, not about rows somebody added.
 *
 * Every scan is idempotent, and not by trying to remember what it has already
 * seen. The row goes in with ON DUPLICATE KEY UPDATE against a unique key on
 * (mattress_id, purpose, context), so a second scan of the same label is
 * refused by MySQL itself — across operators, across devices, across browser
 * tabs, and across a reply that was sent but never arrived.
 */
final class WarehouseService
{
    /** A run large enough for a day's production, bounded so one form cannot issue a million serials. */
    public const MAX_UNITS_PER_BATCH = 2000;

    private LogisticsService $logistics;
    private Lifecycle $lifecycle;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly RequestContext $context,
    ) {
        $this->logistics = new LogisticsService($db, $context);
        $this->lifecycle = new Lifecycle($db);
    }

    public static function instance(): self
    {
        return new self(db_connect(), service('requestContext'));
    }

    // =========================================================================
    // Opening a batch
    // =========================================================================

    /**
     * Creates a batch and issues a serial and QR token for every unit in it,
     * as one operation.
     *
     * The console does this in two steps — create the batch, then produce
     * against it — which suits an office. On the floor it is one screen: the
     * date, the plant, and how many of each size. The planned quantity is the
     * sum of the mix rather than a number typed separately, because two
     * numbers that have to agree are two numbers that will not.
     *
     * @param array{warehouse_id:string, manufactured_on:string, line_supervisor?:?string, notes?:?string} $input
     * @param list<array{product_variant_id:string, quantity:int}>                                         $mix
     *
     * @return array{id:string, batch_code:string, count:int, first_serial:string, last_serial:string}
     */
    public function openBatch(array $input, array $mix): array
    {
        $mix   = array_values(array_filter($mix, static fn ($row) => (int) ($row['quantity'] ?? 0) > 0));
        $total = array_sum(array_map(static fn ($row) => (int) $row['quantity'], $mix));

        if ($total === 0) {
            throw AppException::rule('Enter how many mattresses of at least one size this batch holds.');
        }
        if ($total > self::MAX_UNITS_PER_BATCH) {
            throw AppException::rule('A single batch holds at most ' . self::MAX_UNITS_PER_BATCH . ' mattresses. Open a second batch for the rest.');
        }
        if ($input['manufactured_on'] > local_time(utc_now(), 'Y-m-d')) {
            throw AppException::rule('The manufacturing date cannot be in the future.');
        }

        // Both halves join one transaction (Tx nests), so a batch is never
        // left created but empty, and no serial is burned by a failure.
        return Tx::run(function () use ($input, $mix, $total): array {
            $batch = $this->logistics->createBatch([
                'warehouse_id'     => $input['warehouse_id'],
                'manufactured_on'  => $input['manufactured_on'],
                'planned_quantity' => $total,
                'line_supervisor'  => ($input['line_supervisor'] ?? '') !== '' ? $input['line_supervisor'] : null,
                'notes'            => ($input['notes'] ?? '') !== '' ? $input['notes'] : null,
            ]);

            $produced = $this->logistics->produce($batch['id'], $mix);

            return [
                'id'           => $batch['id'],
                'batch_code'   => $batch['batch_code'],
                'count'        => $produced['count'],
                'first_serial' => $produced['first_serial'],
                'last_serial'  => $produced['last_serial'],
            ];
        }, $this->db);
    }

    // =========================================================================
    // Counting units in
    // =========================================================================

    /**
     * Records one production scan against a batch.
     *
     * Returns the scanner's contract rather than throwing, because every
     * answer here is a normal thing to happen on a floor — a label from
     * another batch, a label scanned twice, a torn label read as nonsense —
     * and the operator needs to be told which, not shown an error page.
     *
     * @return array{ok:bool, status:string, title:string, message:string,
     *               detail?:array<string,string>, counter:array{done:int,total:int,label:string}}
     */
    public function productionScan(string $batchId, string $code, string $source = 'CAMERA'): array
    {
        $batch = $this->db->table('manufacturing_batches')->where('id', $batchId)->get()->getRowArray();
        if ($batch === null) {
            throw AppException::notFound('batch');
        }

        $source = $source === 'MANUAL' ? 'MANUAL' : 'CAMERA';
        $unit   = ScanCode::find($this->db, $code);

        if ($unit === null) {
            return $this->answer($batch, false, 'UNKNOWN', 'Not recognised',
                'That code is not a ' . brand('shortName') . ' mattress label. Check the label, or type the serial.');
        }

        if ($unit['batch_id'] !== $batchId) {
            return $this->answer($batch, false, 'REJECTED', 'Wrong batch',
                $unit['serial_number'] . ' belongs to batch ' . ($unit['batch_code'] ?? 'another batch')
                . '. Open that batch to count it.', $unit);
        }

        // A unit that has left the plant cannot be counted into production
        // again; if it is physically here, something else has gone wrong and
        // an operator pressing Scan is not the place to resolve it.
        if (! in_array($unit['current_status'], ['MANUFACTURED', 'RETURNED'], true)) {
            return $this->answer($batch, false, 'REJECTED', 'Not in the plant',
                $unit['serial_number'] . ' is recorded as ' . strtolower(humanise($unit['current_status']))
                . '. Tell the office before putting it back into stock.', $unit);
        }

        return Tx::run(function (BaseConnection $db) use ($batch, $unit, $source): array {
            // The unique key, not this application, is what makes the count
            // honest: two operators scanning the same label at the same
            // instant both reach here, and only one insert lands.
            $db->query(
                'INSERT INTO warehouse_scans (id, mattress_id, batch_id, dispatch_id, purpose, scanned_by, scanned_at, source)
                 VALUES (?, ?, ?, NULL, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE id = id',
                [uuid4(), $unit['id'], $batch['id'], 'PRODUCTION', $this->context->userId(), utc_now(), $source],
            );

            if ($db->affectedRows() === 0) {
                return $this->answer($batch, false, 'DUPLICATE', 'Already Scanned — No Duplicate Added',
                    $unit['serial_number'] . ' was counted into this batch already.', $unit);
            }

            // Part of this unit's history: when it was physically confirmed,
            // by whom, and whether off the camera or typed.
            $this->lifecycle->event($unit['id'], 'PRODUCTION_SCANNED', 'MANUFACTURED', null, null,
                $this->context->userId(), ['batchCode' => $batch['batch_code'], 'source' => $source]);

            return $this->answer($batch, true, 'ACCEPTED', 'Counted',
                $unit['serial_number'] . ' added to batch ' . $batch['batch_code'] . '.', $unit);
        }, $this->db);
    }

    /**
     * The scanner's reply, with the count read back from the database every
     * time rather than tracked anywhere.
     *
     * @param array<string,mixed>      $batch
     * @param array<string,mixed>|null $unit
     */
    private function answer(array $batch, bool $ok, string $status, string $title, string $message, ?array $unit = null): array
    {
        $progress = $this->batchProgress($batch['id']);

        $reply = [
            'ok'      => $ok,
            'status'  => $status,
            'title'   => $title,
            'message' => $message,
            'counter' => $progress['counter'],
        ];

        if ($unit !== null) {
            $reply['detail'] = array_filter([
                'model' => $unit['product_name'] ?? null,
                'size'  => $unit['size_label'] ?? null,
                'batch' => $unit['batch_code'] ?? null,
                'state' => humanise((string) $unit['current_status']),
            ]);
        }

        return $reply;
    }

    /**
     * How far a batch has got.
     *
     * "Produced" is how many units the batch issued serials for; "scanned" is
     * how many have been physically confirmed. The second can never exceed
     * the first, and the gap is what the operator is working through.
     *
     * @return array{produced:int, scanned:int, remaining:int, planned:int,
     *               counter:array{done:int,total:int,label:string}}
     */
    public function batchProgress(string $batchId): array
    {
        $row = $this->db->query(
            'SELECT b.planned_quantity,
                    (SELECT COUNT(*) FROM mattresses m WHERE m.batch_id = b.id AND m.deleted_at IS NULL) AS produced,
                    (SELECT COUNT(*) FROM warehouse_scans s JOIN mattresses m2 ON m2.id = s.mattress_id
                      WHERE s.batch_id = b.id AND s.purpose = ? AND m2.deleted_at IS NULL) AS scanned
               FROM manufacturing_batches b WHERE b.id = ?',
            ['PRODUCTION', $batchId],
        )->getRowArray();

        $produced = (int) ($row['produced'] ?? 0);
        $scanned  = (int) ($row['scanned'] ?? 0);

        return [
            'planned'   => (int) ($row['planned_quantity'] ?? 0),
            'produced'  => $produced,
            'scanned'   => $scanned,
            'remaining' => max(0, $produced - $scanned),
            'counter'   => [
                'done'  => $scanned,
                'total' => $produced,
                'label' => $scanned . ' / ' . $produced . ' Mattresses Scanned',
            ],
        ];
    }

    // =========================================================================
    // Reading stock back
    // =========================================================================

    /**
     * Free stock at a plant, by model and size.
     *
     * Only MANUFACTURED and RETURNED units are counted: those are the ones
     * that can go on a dispatch. Anything reserved, gone or sold is somebody
     * else's number, and showing it here would have the floor promising stock
     * it cannot send.
     *
     * @return list<array<string,mixed>>
     */
    public function freeStock(?string $warehouseId = null, ?string $search = null): array
    {
        $builder = $this->db->table('mattresses m')
            ->select('p.name AS product_name, v.size_label, v.sku, w.name AS warehouse,'
                . ' COUNT(*) AS available,'
                . " SUM(CASE WHEN m.current_status = 'RETURNED' THEN 1 ELSE 0 END) AS returned", false)
            ->join('product_variants v', 'v.id = m.product_variant_id')
            ->join('products p', 'p.id = v.product_id')
            ->join('warehouses w', 'w.id = m.current_warehouse_id', 'left')
            ->whereIn('m.current_status', ['MANUFACTURED', 'RETURNED'])
            ->where('m.deleted_at', null)
            ->groupBy(['p.name', 'v.size_label', 'v.sku', 'w.name'])
            ->orderBy('p.name')->orderBy('v.size_label');

        if ($warehouseId !== null) {
            $builder->where('m.current_warehouse_id', $warehouseId);
        }
        if ($search !== null && $search !== '') {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search);
            $builder->groupStart()->like('p.name', $term)->orLike('v.size_label', $term)->orLike('v.sku', $term)->groupEnd();
        }

        return $builder->get()->getResultArray();
    }

    /**
     * What the panel's front screen shows. Every figure is counted from the
     * records; none of it is stored or cached, so it cannot drift.
     *
     * @return array<string,int>
     */
    public function dashboard(): array
    {
        $counts = $this->db->query(
            "SELECT
               (SELECT COUNT(*) FROM mattresses WHERE current_status IN ('MANUFACTURED', 'RETURNED') AND deleted_at IS NULL) AS free_stock,
               (SELECT COUNT(*) FROM mattresses WHERE current_status = 'IN_DISPATCH' AND deleted_at IS NULL) AS staged,
               (SELECT COUNT(*) FROM dispatches WHERE status = 'DRAFT' AND deleted_at IS NULL) AS open_dispatches,
               (SELECT COUNT(*) FROM dispatches WHERE status = 'DISPATCHED' AND deleted_at IS NULL) AS in_transit",
        )->getRowArray();

        // A batch is "open" while units it issued serials for have not been
        // scanned yet — that is exactly the work left on the floor.
        $open = $this->db->query(
            "SELECT COUNT(*) AS n FROM manufacturing_batches b
              WHERE (SELECT COUNT(*) FROM mattresses m WHERE m.batch_id = b.id AND m.deleted_at IS NULL)
                  > (SELECT COUNT(*) FROM warehouse_scans s WHERE s.batch_id = b.id AND s.purpose = 'PRODUCTION')",
        )->getRow()->n;

        return array_map('intval', $counts) + ['open_batches' => (int) $open];
    }

    /**
     * Batches, newest first, with how far each has been counted.
     *
     * @return list<array<string,mixed>>
     */
    public function batches(int $limit = 30): array
    {
        return $this->db->query(
            "SELECT b.id, b.batch_code, b.manufactured_on, b.planned_quantity, w.name AS warehouse,
                    (SELECT COUNT(*) FROM mattresses m WHERE m.batch_id = b.id AND m.deleted_at IS NULL) AS produced,
                    (SELECT COUNT(*) FROM warehouse_scans s WHERE s.batch_id = b.id AND s.purpose = 'PRODUCTION') AS scanned
               FROM manufacturing_batches b
               JOIN warehouses w ON w.id = b.warehouse_id
              ORDER BY b.manufactured_on DESC, b.created_at DESC
              LIMIT ?",
            [$limit],
        )->getResultArray();
    }

    /**
     * Every unit in a batch, for the label sheet and for checking the run.
     *
     * @return list<array<string,mixed>>
     */
    public function batchUnits(string $batchId): array
    {
        return $this->db->table('mattresses m')
            ->select('m.id, m.serial_number, m.qr_token, m.current_status, v.size_label, p.name AS product_name,'
                . ' b.batch_code, b.manufactured_on,'
                . ' (SELECT s.scanned_at FROM warehouse_scans s WHERE s.mattress_id = m.id'
                . "  AND s.purpose = 'PRODUCTION' AND s.batch_id = m.batch_id) AS scanned_at", false)
            ->join('product_variants v', 'v.id = m.product_variant_id')
            ->join('products p', 'p.id = v.product_id')
            ->join('manufacturing_batches b', 'b.id = m.batch_id')
            ->where('m.batch_id', $batchId)
            ->where('m.deleted_at', null)
            ->orderBy('m.serial_number')
            ->get()->getResultArray();
    }

    /**
     * Marks a batch counted when the operator says the run is finished short.
     *
     * No status column is set: the figures are the record. What this does is
     * leave a note of who accepted the shortfall and why, so a batch that
     * never reached its planned quantity has an explanation attached rather
     * than looking like an unfinished job for ever.
     */
    public function closeBatch(string $batchId, string $reason): array
    {
        if (trim($reason) === '') {
            throw AppException::rule('Say why this batch is being closed short.');
        }

        return Tx::run(function (BaseConnection $db) use ($batchId, $reason): array {
            $batch = $db->table('manufacturing_batches')->where('id', $batchId)->get()->getRowArray();
            if ($batch === null) {
                throw AppException::notFound('batch');
            }

            $progress = $this->batchProgress($batchId);
            $note     = trim(($batch['notes'] ?? '') . "\n" . 'Closed at ' . $progress['scanned'] . ' of '
                . $progress['produced'] . ' scanned: ' . trim($reason));

            $db->table('manufacturing_batches')->where('id', $batchId)->update(['notes' => mb_substr($note, 0, 4000)]);

            Audit::instance($db)->record('BATCH_CLOSED_SHORT', 'manufacturing_batch', $batchId, null, [
                'batchCode' => $batch['batch_code'],
                'scanned'   => $progress['scanned'],
                'produced'  => $progress['produced'],
                'reason'    => trim($reason),
            ]);

            return ['batch_code' => $batch['batch_code']] + $progress;
        }, $this->db);
    }
}
