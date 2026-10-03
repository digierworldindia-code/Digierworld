<?php

namespace App\Services\Sync;

use App\Libraries\GoogleSheets\SheetsClientInterface;
use App\Services\Settings\SettingsService;
use App\Services\Support\Clock;
use CodeIgniter\Database\BaseConnection;
use Config\Qms;
use Throwable;

/**
 * Processes the Google Sheets outbox (php spark sheets:sync, every minute from cron).
 *
 * - Google is called only here, never inside a business transaction.
 * - Jobs are claimed with an atomic UPDATE, so parallel workers never send the same job.
 * - At-least-once delivery with de-duplication by sync key (column A).
 * - Failures back off exponentially (1, 2, 4 … 360 min ± 20 %); after
 *   google.max_attempts the job is FAILED and waits for a manual retry.
 * - The detail spreadsheet is protected by a capacity guard (10 M cells per spreadsheet).
 */
class SheetSyncWorker
{
    public const CELL_LIMIT = 10_000_000;

    /** @var array<string, array<string, array{sheetId: int, rows: int, cols: int}>> */
    private array $tabs = [];

    /** @var array<string, list<string>> "spreadsheet|tab" => key column */
    private array $keys = [];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly SettingsService $settings,
        private readonly Clock $clock,
        private readonly SheetsClientInterface $client,
        private readonly SheetRowMapper $mapper,
        private readonly Qms $config,
    ) {
    }

    /**
     * @param callable(string): void|null $log
     *
     * @return array{processed: int, synced: int, failed: int, skipped: string|null}
     */
    public function run(int $limit, ?callable $log = null, bool $pause = true): array
    {
        $log ??= static function (string $line): void {};
        $summary = ['processed' => 0, 'synced' => 0, 'failed' => 0, 'skipped' => null];

        if (! $this->settings->bool('google.sync_enabled')) {
            return ['skipped' => 'Google Sheets sync is switched off (Settings → Google Sheets).'] + $summary;
        }
        $main = trim($this->settings->string('google.spreadsheet_id'));
        if ($main === '') {
            return ['skipped' => 'No spreadsheet ID configured.'] + $summary;
        }
        $credentials = $this->client->describeCredentials();
        if (! $credentials['configured']) {
            $this->heartbeat(false, $credentials['hint']);

            return ['skipped' => $credentials['hint']] + $summary;
        }

        // Only one worker at a time (cron also uses flock).
        $lock = (int) $this->db->query("SELECT GET_LOCK('qms_sheets_sync', 0) AS l")->getRow('l');
        if ($lock !== 1) {
            return ['skipped' => 'Another sync worker is running.'] + $summary;
        }

        try {
            $this->tabs = [];
            $this->keys = [];
            $this->reclaimStale();
            $worker = substr(gethostname() . ':' . getmypid(), 0, 64);
            $lastError = null;

            for ($i = 0; $i < $limit; $i++) {
                $job = $this->db->table('google_sheet_sync_logs')
                    ->where('sync_status', 'PENDING_SYNC')->where('next_attempt_at <=', $this->clock->nowUtcString())
                    ->orderBy('next_attempt_at')->orderBy('id')->get(1)->getRowArray();
                if ($job === null) {
                    break;
                }
                if (! $this->claim($job, $worker)) {
                    continue;
                }
                $summary['processed']++;
                $attempt = (int) $job['attempt_count'] + 1;

                try {
                    [$spreadsheet, $range] = $job['job_type'] === SheetSyncQueue::REPORT_UPSERT
                        ? $this->upsertReport($job, $main)
                        : $this->appendObservations($job, $main, $attempt);
                    $this->succeed($job, $spreadsheet, $range);
                    $summary['synced']++;
                    $log("#{$job['id']} {$job['job_type']} report {$job['report_id']} → {$range}");
                } catch (CapacityException $e) {
                    $this->postpone($job, $e->getMessage());
                    $lastError = $e->getMessage();
                    $log("#{$job['id']} postponed: {$e->getMessage()}");
                } catch (Throwable $e) {
                    $message   = self::sanitize($e);
                    $lastError = $message;
                    $this->fail($job, $attempt, $message);
                    $summary['failed']++;
                    $log("#{$job['id']} attempt {$attempt} failed: {$message}");
                }

                if ($pause && $this->config->syncPauseMilliseconds > 0) {
                    usleep($this->config->syncPauseMilliseconds * 1000);
                }
            }
            $this->heartbeat($summary['failed'] === 0, $lastError, $summary);
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('qms_sheets_sync')");
        }

        return $summary;
    }

    /**
     * Retry delay for the given attempt number (1-based): min(2^(n-1) min, 6 h) ± 20 %.
     */
    public static function backoffSeconds(int $attempt, ?float $jitter = null): int
    {
        $minutes = min(2 ** max(0, $attempt - 1), 360);
        $jitter ??= random_int(-200, 200) / 1000;

        return max(30, (int) round($minutes * 60 * (1 + $jitter)));
    }

    /**
     * Error text safe to store and show: no tokens, keys or headers, at most 2000 characters.
     */
    public static function sanitize(Throwable $e): string
    {
        $message = $e->getMessage();
        $decoded = json_decode($message, true);
        if (is_array($decoded) && isset($decoded['error'])) {
            $error   = $decoded['error'];
            $message = is_array($error)
                ? trim(($error['code'] ?? '') . ' ' . ($error['status'] ?? '') . ': ' . ($error['message'] ?? ''))
                : (string) $error;
        }
        $message = preg_replace([
            '/-----BEGIN [A-Z ]+-----.*?-----END [A-Z ]+-----/s',
            '/ya29\.[0-9A-Za-z\-_.]+/',
            '/Bearer\s+[0-9A-Za-z\-_.~+\/]+=*/i',
            '/(access_token|refresh_token|private_key|client_secret|assertion)(["\']?\s*[:=]\s*)["\']?[^"\'&\s,}]+/i',
        ], ['[key removed]', '[token removed]', 'Bearer [token removed]', '$1$2[removed]'], $message) ?? 'Error';
        $code = (int) $e->getCode();

        return mb_substr(($code > 0 && ! str_contains($message, (string) $code) ? "HTTP {$code}: " : '') . trim($message), 0, 2000);
    }

    // ================================================================ jobs

    /**
     * @param array<string, mixed> $job
     *
     * @return array{0: string, 1: string} spreadsheet id, range
     */
    private function upsertReport(array $job, string $spreadsheet): array
    {
        $row   = $this->mapper->reportRow((int) $job['report_id']);
        $sheet = (string) $job['sheet_name'];
        $this->ensureTab($spreadsheet, $sheet, SheetRowMapper::REPORT_HEADERS);

        $keys  = $this->keyColumn($spreadsheet, $sheet, false);
        $index = array_search($row['key'], $keys, true);
        if ($index !== false && $index > 0) {
            $range = $this->client->updateRow($spreadsheet, $sheet, $index + 1, $row['values']);
        } else {
            $range = $this->client->appendRows($spreadsheet, $sheet, [$row['values']]);
            $this->keys[$spreadsheet . '|' . $sheet][] = $row['key'];
        }

        return [$spreadsheet, $range];
    }

    /**
     * @param array<string, mixed> $job
     *
     * @return array{0: string, 1: string}
     */
    private function appendObservations(array $job, string $main, int $attempt): array
    {
        $spreadsheet = trim($this->settings->string('google.detail_spreadsheet_id'));
        $spreadsheet = $spreadsheet === '' ? $main : $spreadsheet;
        $mode        = strtoupper($this->settings->string('google.observation_detail', 'ALL'));
        if ($mode === 'NONE') {
            return [$spreadsheet, 'not sent (observation detail switched off)'];
        }

        $rows = $this->mapper->observationRows((int) $job['report_id'], $mode);
        if ($rows === []) {
            return [$spreadsheet, 'nothing to send'];
        }
        $sheet = (string) $job['sheet_name'];
        $this->ensureTab($spreadsheet, $sheet, SheetRowMapper::OBSERVATION_HEADERS);
        $this->guardCapacity($spreadsheet, count($rows) * count(SheetRowMapper::OBSERVATION_HEADERS));

        if ($attempt > 1) {
            // An earlier attempt may have reached Google before failing: skip rows already there.
            $present = array_flip($this->keyColumn($spreadsheet, $sheet, true));
            $rows    = array_values(array_filter($rows, static fn (array $r): bool => ! isset($present[$r['key']])));
            if ($rows === []) {
                return [$spreadsheet, 'already present'];
            }
        }

        $range = $this->client->appendRows($spreadsheet, $sheet, array_column($rows, 'values'));

        return [$spreadsheet, $range];
    }

    /**
     * @param list<string> $headers
     */
    private function ensureTab(string $spreadsheet, string $sheet, array $headers): void
    {
        if (! isset($this->tabs[$spreadsheet])) {
            $this->tabs[$spreadsheet] = $this->client->sheets($spreadsheet);
        }
        if (! isset($this->tabs[$spreadsheet][$sheet])) {
            $this->client->addSheet($spreadsheet, $sheet, $headers);
            $this->tabs[$spreadsheet] = $this->client->sheets($spreadsheet);
            $this->keys[$spreadsheet . '|' . $sheet] = [$headers[0]];
        }
    }

    /**
     * @return list<string>
     */
    private function keyColumn(string $spreadsheet, string $sheet, bool $refresh): array
    {
        $cacheKey = $spreadsheet . '|' . $sheet;
        if ($refresh || ! isset($this->keys[$cacheKey])) {
            $this->keys[$cacheKey] = $this->client->readKeyColumn($spreadsheet, $sheet);
        }

        return $this->keys[$cacheKey];
    }

    private function guardCapacity(string $spreadsheet, int $newCells): void
    {
        $this->tabs[$spreadsheet] = $this->client->sheets($spreadsheet);
        $cells = 0;
        foreach ($this->tabs[$spreadsheet] as $tab) {
            $cells += $tab['rows'] * $tab['cols'];
        }
        $percent = max(10, min(99, $this->settings->int('google.capacity_alert_percent', 80)));
        if ($cells + $newCells >= self::CELL_LIMIT * $percent / 100) {
            throw new CapacityException(sprintf(
                'Detail spreadsheet is %d %% full (%s of 10,000,000 cells). Create a new detail spreadsheet, share it with the service account and enter its ID in Settings. Jobs wait until then.',
                (int) floor($cells * 100 / self::CELL_LIMIT), number_format($cells),
            ));
        }
    }

    // ================================================================ state

    /**
     * @param array<string, mixed> $job
     */
    private function claim(array $job, string $worker): bool
    {
        $now = $this->clock->nowUtcString();
        $this->db->table('google_sheet_sync_logs')
            ->where('id', $job['id'])->where('sync_status', 'PENDING_SYNC')
            ->set('attempt_count', 'attempt_count + 1', false)
            ->update(['sync_status' => 'PROCESSING', 'locked_at' => $now, 'locked_by' => $worker, 'last_attempt_at' => $now]);

        return $this->db->affectedRows() === 1;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function succeed(array $job, string $spreadsheet, string $range): void
    {
        $now = $this->clock->nowUtcString();
        $this->db->table('google_sheet_sync_logs')->where('id', $job['id'])->update([
            'sync_status' => 'SYNCED', 'synced_at' => $now, 'spreadsheet_id' => mb_substr($spreadsheet, 0, 100),
            'sheet_range' => mb_substr($range, 0, 120), 'error_message' => null, 'locked_at' => null, 'locked_by' => null,
        ]);
        if ($job['job_type'] === SheetSyncQueue::REPORT_UPSERT) {
            // Older waiting / failed upserts of the same report are covered by this newer state.
            $this->db->table('google_sheet_sync_logs')
                ->where('report_id', $job['report_id'])->where('job_type', SheetSyncQueue::REPORT_UPSERT)
                ->where('id <', $job['id'])->whereIn('sync_status', ['PENDING_SYNC', 'FAILED'])
                ->update(['sync_status' => 'SYNCED', 'synced_at' => $now, 'spreadsheet_id' => mb_substr($spreadsheet, 0, 100),
                    'sheet_range' => mb_substr($range, 0, 120), 'error_message' => 'Covered by job #' . $job['id']]);
        }
    }

    /**
     * @param array<string, mixed> $job
     */
    private function fail(array $job, int $attempt, string $message): void
    {
        $max   = max(1, $this->settings->int('google.max_attempts', 12));
        $final = $attempt >= $max;
        $this->db->table('google_sheet_sync_logs')->where('id', $job['id'])->update([
            'sync_status'     => $final ? 'FAILED' : 'PENDING_SYNC',
            'next_attempt_at' => $this->clock->nowUtc()->modify('+' . self::backoffSeconds($attempt) . ' seconds')->format('Y-m-d H:i:s'),
            'error_message'   => $message,
            'locked_at'       => null,
            'locked_by'       => null,
        ]);
        if ($final) {
            log_message('error', 'Google Sheets sync job {id} FAILED after {n} attempts: {msg}', ['id' => $job['id'], 'n' => $attempt, 'msg' => $message]);
        }
    }

    /**
     * Capacity problems are not the job's fault: wait an hour, do not count the attempt.
     *
     * @param array<string, mixed> $job
     */
    private function postpone(array $job, string $message): void
    {
        $this->db->table('google_sheet_sync_logs')->where('id', $job['id'])
            ->set('attempt_count', 'GREATEST(attempt_count - 1, 0)', false)
            ->update([
                'sync_status'     => 'PENDING_SYNC',
                'next_attempt_at' => $this->clock->nowUtc()->modify('+1 hour')->format('Y-m-d H:i:s'),
                'error_message'   => mb_substr($message, 0, 2000),
                'locked_at'       => null,
                'locked_by'       => null,
            ]);
    }

    /** Jobs left PROCESSING by a crashed worker go back to the queue. */
    private function reclaimStale(): void
    {
        $limit = $this->clock->nowUtc()->modify('-' . max(1, $this->config->syncStaleLockMinutes) . ' minutes')->format('Y-m-d H:i:s');
        $this->db->table('google_sheet_sync_logs')->where('sync_status', 'PROCESSING')->where('locked_at <', $limit)->update([
            'sync_status' => 'PENDING_SYNC', 'locked_at' => null, 'locked_by' => null, 'next_attempt_at' => $this->clock->nowUtcString(),
        ]);
    }

    /**
     * Last run information for the Sync Monitor (writable/sync-heartbeat.json).
     *
     * @param array<string, mixed> $summary
     */
    private function heartbeat(bool $ok, ?string $error, array $summary = []): void
    {
        $file    = WRITEPATH . 'sync-heartbeat.json';
        $current = self::readHeartbeat();
        $now     = $this->clock->nowUtcString();
        $data    = [
            'last_run'     => $now,
            'last_success' => $ok ? $now : ($current['last_success'] ?? null),
            'last_error'   => $error ?? ($ok ? null : ($current['last_error'] ?? null)),
            'last_summary' => $summary,
        ];
        @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * @return array<string, mixed>
     */
    public static function readHeartbeat(): array
    {
        $file = WRITEPATH . 'sync-heartbeat.json';
        if (! is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }
}
