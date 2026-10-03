<?php

namespace App\Services\Sync;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Paging;
use App\Services\Audit\AuditService;
use App\Services\Settings\SettingsService;
use App\Services\Support\Clock;
use CodeIgniter\Database\BaseConnection;

/**
 * Transactional outbox for Google Sheets.
 *
 * enqueue*() must be called inside the business transaction (submit, approve ...),
 * so a committed change always has its sync job and a rolled-back change has none.
 * Google itself is only called later by SheetSyncWorker (php spark sheets:sync).
 *
 * Jobs are queued as soon as the integration is configured (sync switched on or
 * a spreadsheet ID entered). While google.sync_enabled is off the jobs simply wait,
 * so pausing the integration never loses rows. Reports created before the
 * integration was configured can be queued with backfill().
 */
class SheetSyncQueue
{
    public const REPORT_UPSERT       = 'REPORT_UPSERT';
    public const OBSERVATIONS_APPEND = 'OBSERVATIONS_APPEND';
    public const STATUSES            = ['PENDING_SYNC', 'PROCESSING', 'SYNCED', 'FAILED'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly SettingsService $settings,
        private readonly Clock $clock,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->settings->bool('google.sync_enabled') || trim($this->settings->string('google.spreadsheet_id')) !== '';
    }

    /**
     * Queues the latest state of the report for the Reports tab. Coalesces with a
     * job that is still waiting: the worker always sends the current state.
     */
    public function enqueueReport(int $reportId): void
    {
        if (! $this->isConfigured()) {
            return;
        }
        $waiting = $this->db->table('google_sheet_sync_logs')
            ->where('report_id', $reportId)->where('job_type', self::REPORT_UPSERT)->where('sync_status', 'PENDING_SYNC')
            ->countAllResults();
        if ($waiting > 0) {
            return;
        }
        $this->insert($reportId, self::REPORT_UPSERT, $this->reportsSheet());
    }

    /**
     * Queues the readings of a final report (approved or rejected) for the monthly
     * observation tab. Readings never change after that, so one job per report.
     */
    public function enqueueObservations(int $reportId, string $inspectionDate): void
    {
        if (! $this->isConfigured() || $this->detailMode() === 'NONE') {
            return;
        }
        $exists = $this->db->table('google_sheet_sync_logs')
            ->where('report_id', $reportId)->where('job_type', self::OBSERVATIONS_APPEND)->where('sync_status !=', 'FAILED')
            ->countAllResults();
        if ($exists > 0) {
            return;
        }
        $this->insert($reportId, self::OBSERVATIONS_APPEND, $this->observationSheet($inspectionDate));
    }

    public function detailMode(): string
    {
        $mode = strtoupper($this->settings->string('google.observation_detail', 'ALL'));

        return in_array($mode, ['ALL', 'OOS_ONLY', 'NONE'], true) ? $mode : 'ALL';
    }

    public function reportsSheet(): string
    {
        $name = trim($this->settings->string('google.reports_sheet', 'Reports'));

        return $name === '' ? 'Reports' : mb_substr($name, 0, 100);
    }

    public function observationSheet(string $inspectionDate): string
    {
        $prefix = trim($this->settings->string('google.observations_sheet_prefix', 'Observations_'));

        return mb_substr(($prefix === '' ? 'Observations_' : $prefix) . substr($inspectionDate, 0, 4) . '_' . substr($inspectionDate, 5, 2), 0, 100);
    }

    private function insert(int $reportId, string $jobType, string $sheet): void
    {
        $now = $this->clock->nowUtcString();
        $this->db->table('google_sheet_sync_logs')->insert([
            'report_id'       => $reportId,
            'job_type'        => $jobType,
            'sheet_name'      => $sheet,
            'sync_status'     => 'PENDING_SYNC',
            'attempt_count'   => 0,
            'next_attempt_at' => $now,
            'created_at'      => $now,
        ]);
    }

    // ================================================================ monitor

    /**
     * Least advanced sync status of a report's jobs (detail page badge); null = never queued.
     */
    public function reportState(int $reportId): ?string
    {
        $statuses = array_column($this->db->table('google_sheet_sync_logs')->select('sync_status')
            ->where('report_id', $reportId)->get()->getResultArray(), 'sync_status');
        foreach (['FAILED', 'PROCESSING', 'PENDING_SYNC', 'SYNCED'] as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }

        return null;
    }

    /**
     * @return array<string, int> status => count, plus 'oldest_pending_minutes'
     */
    public function summary(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($this->db->table('google_sheet_sync_logs')->select('sync_status, COUNT(*) AS n')->groupBy('sync_status')->get()->getResultArray() as $row) {
            $counts[$row['sync_status']] = (int) $row['n'];
        }
        $oldest = $this->db->table('google_sheet_sync_logs')->selectMin('created_at', 'm')
            ->whereIn('sync_status', ['PENDING_SYNC', 'PROCESSING'])->get()->getRow('m');
        $counts['oldest_pending_minutes'] = $oldest === null ? 0
            : max(0, (int) floor(($this->clock->nowUtc()->getTimestamp() - strtotime($oldest . ' UTC')) / 60));

        return $counts;
    }

    /**
     * Distinct reports whose latest data is not yet in Google Sheets (dashboard card).
     */
    public function pendingReportCount(): int
    {
        return (int) $this->db->table('google_sheet_sync_logs')->select('COUNT(DISTINCT report_id) AS n', false)
            ->whereIn('sync_status', ['PENDING_SYNC', 'PROCESSING', 'FAILED'])->get()->getRow('n');
    }

    /**
     * @param array<string, mixed> $filters status, job_type, q (report number)
     *
     * @return list<array<string, mixed>>
     */
    public function list(array $filters, Paging $paging): array
    {
        $builder = $this->db->table('google_sheet_sync_logs j')
            ->select('j.*, r.report_no, r.revision_no, r.status AS report_status, rt.code AS type_code')
            ->join('inspection_reports r', 'r.id = j.report_id')
            ->join('report_types rt', 'rt.id = r.report_type_id');
        if (in_array($filters['status'] ?? '', self::STATUSES, true)) {
            $builder->where('j.sync_status', $filters['status']);
        }
        if (in_array($filters['job_type'] ?? '', [self::REPORT_UPSERT, self::OBSERVATIONS_APPEND], true)) {
            $builder->where('j.job_type', $filters['job_type']);
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $builder->like('r.report_no', $q);
        }
        $paging->total = (int) $builder->countAllResults(false);

        return $builder->orderBy('j.id', 'DESC')->limit($paging->perPage, $paging->offset())->get()->getResultArray();
    }

    /**
     * Puts a FAILED (or stuck) job back in the queue for an immediate attempt.
     */
    public function retry(int $jobId, AuditService $audit): void
    {
        $job = $this->db->table('google_sheet_sync_logs')->where('id', $jobId)->get(1)->getRowArray()
            ?? throw new NotFoundException('Sync job not found.');
        if ($job['sync_status'] === 'SYNCED') {
            throw ValidationException::single('job', 'This job is already synchronised.');
        }
        if ($job['sync_status'] === 'PROCESSING' && $job['locked_at'] !== null
            && strtotime($job['locked_at'] . ' UTC') > $this->clock->nowUtc()->getTimestamp() - 600) {
            throw ValidationException::single('job', 'The worker is processing this job right now.');
        }
        $this->db->table('google_sheet_sync_logs')->where('id', $jobId)->update([
            'sync_status' => 'PENDING_SYNC', 'attempt_count' => 0, 'next_attempt_at' => $this->clock->nowUtcString(),
            'locked_at' => null, 'locked_by' => null,
        ]);
        $audit->log('SYNC_RETRY', 'sync', $jobId, ['status' => $job['sync_status'], 'attempts' => (int) $job['attempt_count']], ['status' => 'PENDING_SYNC'], 'report #' . $job['report_id']);
    }

    public function retryFailed(AuditService $audit): int
    {
        $this->db->table('google_sheet_sync_logs')->where('sync_status', 'FAILED')->update([
            'sync_status' => 'PENDING_SYNC', 'attempt_count' => 0, 'next_attempt_at' => $this->clock->nowUtcString(),
            'locked_at' => null, 'locked_by' => null,
        ]);
        $count = $this->db->affectedRows();
        $audit->log('SYNC_RETRY_ALL', 'sync', null, null, ['jobs' => $count]);

        return $count;
    }

    /**
     * Queues every numbered report of a date range (after the integration was
     * configured late, or after a spreadsheet was replaced). Rows are de-duplicated
     * by the worker, so running it twice does no harm.
     */
    public function backfill(string $from, string $to, AuditService $audit): int
    {
        foreach ([$from, $to] as $date) {
            if (\DateTimeImmutable::createFromFormat('!Y-m-d', $date) === false) {
                throw ValidationException::single('from', 'Enter the dates as YYYY-MM-DD.');
            }
        }
        if ($to < $from) {
            throw ValidationException::single('to', 'The end date must be on or after the start date.');
        }
        if (! $this->isConfigured()) {
            throw ValidationException::single('google', 'Configure the spreadsheet ID first (Settings → Google Sheets).');
        }
        $reports = $this->db->table('inspection_reports')->select('id, status, inspection_date')
            ->where('report_no IS NOT NULL')->where('inspection_date >=', $from)->where('inspection_date <=', $to)
            ->orderBy('id')->get()->getResultArray();
        if (count($reports) > 5000) {
            throw ValidationException::single('to', 'Choose a shorter period (at most 5,000 reports at a time).');
        }
        foreach ($reports as $report) {
            $this->enqueueReport((int) $report['id']);
            if (in_array($report['status'], ['QA_APPROVED', 'REJECTED', 'SUPERSEDED'], true)) {
                $this->enqueueObservations((int) $report['id'], (string) $report['inspection_date']);
            }
        }
        $audit->log('SYNC_BACKFILL', 'sync', null, null, ['from' => $from, 'to' => $to, 'reports' => count($reports)]);

        return count($reports);
    }
}
