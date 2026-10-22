<?php

namespace App\Services\Sync;

use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * One-way MySQL → Google Sheets sync through a durable queue.
 *
 *  - enqueue() runs inside the caller's DB transaction: if the CRM save
 *    commits, the job exists; Google being offline can never lose the save.
 *  - One job row per logical record (idempotency key worksheet:entity:id).
 *    A newer change re-arms the same job instead of adding duplicates.
 *  - process() writes the record's CURRENT state, upserting by the stable
 *    ID in column A (row map + column scan), so retries never duplicate rows.
 *  - Failures back off exponentially; after max attempts a job is "dead"
 *    and visible on the sync dashboard with its last error and Retry.
 */
class SheetsSyncService
{
    private ?SheetsClient $client = null;
    private ?string $clientError = null;

    /** @var array<string, list<string>> column A per worksheet title, read once per process() run */
    private array $columnCache = [];

    public function __construct(private readonly BaseConnection $db, ?SheetsClient $client = null)
    {
        $this->client = $client;
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        $s = $this->db->table('google_sync_settings')->where('id', 1)->get()->getRowArray() ?? ['enabled' => 0];
        $s['worksheets'] = json_decode((string) ($s['worksheets'] ?? '{}'), true) ?: [];
        $s['field_map']  = json_decode((string) ($s['field_map'] ?? '{}'), true) ?: [];
        foreach (SheetsRowBuilder::ENTITIES as $ws => $_) {
            $s['worksheets'][$ws] ??= ucwords(str_replace('_', ' ', $ws));
        }

        return $s;
    }

    public function credentialsPath(): ?string
    {
        $p = (string) ($this->settings()['credentials_path'] ?? '') ?: (string) env('google.credentialsPath', '');
        if ($p === '') {
            return null;
        }
        if (! str_starts_with($p, '/')) {
            $p = ROOTPATH . ltrim($p, '/');
        }
        // A key inside the public web root could be downloaded — refuse it.
        if (str_starts_with(realpath($p) ?: $p, rtrim(FCPATH, '/'))) {
            return null;
        }

        return $p;
    }

    /** Live client, or null with a reason when the integration is not configured. */
    public function client(): ?SheetsClient
    {
        if ($this->client !== null) {
            return $this->client;
        }
        $s = $this->settings();
        if (empty($s['enabled']) || empty($s['spreadsheet_id'])) {
            $this->clientError = 'Sync is not enabled or no spreadsheet ID is set.';

            return null;
        }
        $path = $this->credentialsPath();
        if ($path === null) {
            $this->clientError = 'No service-account key configured (google.credentialsPath), or it is inside the public folder.';

            return null;
        }
        try {
            return $this->client = new GoogleSheetsClient((string) $s['spreadsheet_id'], $path);
        } catch (Throwable $e) {
            $this->clientError = $e->getMessage();

            return null;
        }
    }

    public function clientError(): ?string
    {
        return $this->clientError;
    }

    /** True when jobs should be queued at all (enabled), even if Google is unreachable right now. */
    public function isEnabled(): bool
    {
        return ! empty($this->settings()['enabled']);
    }

    /**
     * Queue (or re-arm) the sync of one record. Call inside the same DB
     * transaction as the change. Queues even when sync is disabled so that
     * enabling it later catches up — the dashboard shows the backlog.
     */
    public function enqueue(string $worksheet, int $entityId, string $action = 'upsert'): void
    {
        if (! isset(SheetsRowBuilder::ENTITIES[$worksheet])) {
            return;
        }
        $entity = SheetsRowBuilder::ENTITIES[$worksheet];
        $this->db->query(
            "INSERT INTO google_sync_jobs (entity_type, entity_id, worksheet, action, idempotency_key, status, attempts, max_attempts, next_attempt_at, created_at)
             VALUES (?, ?, ?, ?, ?, 'pending', 0, 8, NOW(), NOW())
             ON DUPLICATE KEY UPDATE status = 'pending', attempts = 0, action = VALUES(action), next_attempt_at = NOW(), last_error = NULL",
            [$entity, $entityId, $worksheet, $action, $worksheet . ':' . $entity . ':' . $entityId]
        );
    }

    /** Re-arm the daily summary row of a user for a date. */
    public function enqueueDaily(string $date, int $userId): void
    {
        $this->enqueue('daily', SheetsRowBuilder::dailyKey($date, $userId));
    }

    /**
     * Process due jobs. Safe to run from cron every few minutes and from
     * the "Sync now" button concurrently (jobs are claimed atomically).
     *
     * From a web request pass $budgetSeconds: work stops claiming new jobs
     * once that time is used up (the rest stays queued for cron or the next
     * click), so the page answers before the gateway times out.
     *
     * @return array{processed: int, succeeded: int, failed: int, remaining: int, message: string}
     */
    public function process(int $limit = 100, string $trigger = 'cron', ?float $budgetSeconds = null): array
    {
        $started = microtime(true);
        $runId = null;
        $this->db->table('google_sync_runs')->insert(['started_at' => date('Y-m-d H:i:s'), 'trigger_source' => $trigger, 'processed' => 0, 'succeeded' => 0, 'failed' => 0]);
        $runId = (int) $this->db->insertID();
        $stats = ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'remaining' => 0, 'message' => ''];

        $client = $this->client();
        if ($client === null) {
            $stats['message'] = 'Not connected: ' . $this->clientError;
            $this->finishRun($runId, $stats);

            return $stats;
        }
        $settings = $this->settings();
        $worker   = gethostname() . ':' . getmypid() . ':' . bin2hex(random_bytes(3));
        $builder  = new SheetsRowBuilder($this->db);
        $ensured  = [];
        $this->columnCache = [];
        $limit    = max(1, min(500, $limit));

        // Claim due jobs atomically, a small batch at a time
        while ($stats['processed'] < $limit && ($budgetSeconds === null || microtime(true) - $started < $budgetSeconds)) {
            $this->db->query(
                "UPDATE google_sync_jobs SET status = 'processing', locked_at = NOW(), locked_by = ?
                  WHERE status IN ('pending','failed') AND next_attempt_at <= NOW() ORDER BY id LIMIT " . min(25, $limit - $stats['processed']),
                [$worker]
            );
            $jobs = $this->db->table('google_sync_jobs')->where(['status' => 'processing', 'locked_by' => $worker])->orderBy('id')->get()->getResultArray();
            if ($jobs === []) {
                break;
            }

            foreach ($jobs as $job) {
                $stats['processed']++;
                $ws    = $job['worksheet'];
                $title = (string) $settings['worksheets'][$ws];
                $cols  = SheetsRowBuilder::columns($ws, $settings['field_map'][$ws] ?? null);
                try {
                    if (! isset($ensured[$title])) {
                        $client->ensureSheet($title, SheetsRowBuilder::headers($ws, $cols));
                        $ensured[$title] = true;
                    }
                    $row = $builder->row($ws, (int) $job['entity_id'], $cols);
                    if ($row === null) {
                        // Record vanished (hard delete); nothing to write.
                        $this->complete((int) $job['id'], $worker, null);
                        $stats['succeeded']++;

                        continue;
                    }
                    $sheetRow = $this->upsert($client, $title, $ws, $job, $row);
                    $this->complete((int) $job['id'], $worker, $sheetRow);
                    $stats['succeeded']++;
                } catch (Throwable $e) {
                    $stats['failed']++;
                    $this->fail($job, $worker, $e);
                }
            }
        }

        $stats['remaining'] = $this->db->table('google_sync_jobs')->whereIn('status', ['pending', 'failed'])->where('next_attempt_at <= NOW()', null, false)->countAllResults();
        $stats['message'] = $stats['processed'] === 0 ? 'Nothing to sync.' : "{$stats['succeeded']} synced, {$stats['failed']} failed.";
        if ($stats['remaining'] > 0 && $stats['processed'] > 0) {
            $stats['message'] .= " {$stats['remaining']} still queued (the scheduled job continues, or press Sync now again).";
        }
        $this->finishRun($runId, $stats);
        $upd = ['last_run_at' => date('Y-m-d H:i:s')];
        if ($stats['succeeded'] > 0) {
            $upd['last_success_at'] = date('Y-m-d H:i:s');
        }
        $this->db->table('google_sync_settings')->where('id', 1)->update($upd);

        return $stats;
    }

    /** Write the row, reusing its known sheet row; never append a second row for the same ID. */
    private function upsert(SheetsClient $client, string $title, string $ws, array $job, array $row): int
    {
        $map = $this->db->table('google_sync_row_map')->where(['worksheet' => $title, 'entity_type' => $job['entity_type'], 'entity_id' => $job['entity_id']])->get()->getRowArray();
        $id  = $row[0];
        $hash = hash('sha256', json_encode(array_slice($row, 0, -1) ?: $row));
        $target = null;
        // column A (the record IDs) is read once per run and per worksheet, not once per record
        $colA = $this->columnCache[$title] ??= $client->columnA($title);
        if ($map !== null) {
            if (($colA[(int) $map['sheet_row'] - 1] ?? null) === $id) {
                $target = (int) $map['sheet_row'];
            } else {
                // Someone sorted/inserted rows in the sheet: find the ID again.
                $pos    = array_search($id, $colA, true);
                $target = $pos === false ? null : $pos + 1;
            }
        } else {
            $pos    = array_search($id, $colA, true);
            $target = $pos === false || $pos === 0 ? null : $pos + 1;
        }
        if ($target !== null) {
            $client->updateRow($title, $target, $row);
        } else {
            $target = $client->appendRow($title, $row);
            for ($i = count($this->columnCache[$title]); $i < $target - 1; $i++) {
                $this->columnCache[$title][$i] = '';
            }
            $this->columnCache[$title][$target - 1] = $id;
        }
        $this->db->query(
            'INSERT INTO google_sync_row_map (worksheet, entity_type, entity_id, sheet_row, last_synced_hash, last_synced_at) VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE sheet_row = VALUES(sheet_row), last_synced_hash = VALUES(last_synced_hash), last_synced_at = NOW()',
            [$title, $job['entity_type'], $job['entity_id'], $target, $hash]
        );

        return $target;
    }

    private function complete(int $jobId, string $worker, ?int $sheetRow): void
    {
        // If the record changed while we were writing, enqueue() has already re-armed the job
        // (status back to 'pending'), so this update does not match and the newer state syncs next run.
        $this->db->query(
            "UPDATE google_sync_jobs SET status = 'done', last_success_at = NOW(), last_error = NULL, remote_row_ref = ?, locked_at = NULL, locked_by = NULL
              WHERE id = ? AND locked_by = ? AND status = 'processing'",
            [$sheetRow !== null ? (string) $sheetRow : null, $jobId, $worker]
        );
    }

    private function fail(array $job, string $worker, Throwable $e): void
    {
        $attempts = (int) $job['attempts'] + 1;
        $dead     = $attempts >= (int) $job['max_attempts'];
        // 1, 2, 4, 8 … minutes, capped at 6 hours, plus jitter to spread retries
        $delay = min(360, 2 ** ($attempts - 1)) * 60 + random_int(0, 30);
        $msg   = mb_substr(preg_replace('/\s+/', ' ', strip_tags($e->getMessage())) ?? '', 0, 900);
        $this->db->query(
            "UPDATE google_sync_jobs SET status = ?, attempts = ?, next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND), last_error = ?, locked_at = NULL, locked_by = NULL
              WHERE id = ? AND locked_by = ? AND status = 'processing'",
            [$dead ? 'dead' : 'failed', $attempts, $delay, $msg, $job['id'], $worker]
        );
        $this->db->table('google_sync_settings')->where('id', 1)->update(['last_error' => $msg, 'last_error_at' => date('Y-m-d H:i:s')]);
        log_message('warning', 'Sheets sync job ' . $job['id'] . ' failed (attempt ' . $attempts . '): ' . $msg);
    }

    private function finishRun(int $runId, array $stats): void
    {
        $this->db->table('google_sync_runs')->where('id', $runId)->update([
            'finished_at' => date('Y-m-d H:i:s'), 'processed' => $stats['processed'], 'succeeded' => $stats['succeeded'], 'failed' => $stats['failed'], 'message' => mb_substr($stats['message'], 0, 1000),
        ]);
    }

    /** Retry failed/dead jobs now ("Retry now" button). */
    public function retryFailed(): int
    {
        $this->db->query("UPDATE google_sync_jobs SET status = 'pending', attempts = 0, next_attempt_at = NOW() WHERE status IN ('failed','dead')");

        return $this->db->affectedRows();
    }

    /** Re-queue every CRM record (after enabling sync or changing columns). */
    public function queueAll(): int
    {
        $n = 0;
        $this->db->transBegin();
        try {
            foreach ([['leads', 'leads'], ['calls', 'call_logs'], ['followups', 'followups'], ['visits', 'visits'], ['conversions', 'lead_conversion_links']] as [$ws, $table]) {
                foreach ($this->db->table($table)->select('id')->get()->getResultArray() as $r) {
                    $this->enqueue($ws, (int) $r['id']);
                    $n++;
                }
            }
            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        return $n;
    }

    /** @return array<string, mixed> dashboard numbers */
    public function status(): array
    {
        $counts = array_column($this->db->query('SELECT status, COUNT(*) n FROM google_sync_jobs GROUP BY status')->getResultArray(), 'n', 'status');
        $s = $this->settings();

        return [
            'enabled'      => ! empty($s['enabled']),
            'spreadsheet'  => $s['spreadsheet_id'] ?? null,
            'pending'      => (int) ($counts['pending'] ?? 0) + (int) ($counts['processing'] ?? 0),
            'failed'       => (int) ($counts['failed'] ?? 0),
            'dead'         => (int) ($counts['dead'] ?? 0),
            'done'         => (int) ($counts['done'] ?? 0),
            'last_success' => $s['last_success_at'] ?? null,
            'last_error'   => $s['last_error'] ?? null,
            'last_error_at' => $s['last_error_at'] ?? null,
            'last_run'     => $s['last_run_at'] ?? null,
        ];
    }
}
