<?php
/**
 * Google Sheets outbox (table google_sheet_sync_logs).
 *
 * sheet_enqueue_*() are called inside the business transaction (submit,
 * approve …): a committed change always has its sync job and a rolled-back
 * change has none. Google itself is only called later by cron/sheets_sync.php
 * (includes/sheets_worker.php), so the shop floor never waits for Google.
 *
 * Jobs are queued as soon as the integration is configured (sync switched on or
 * a spreadsheet ID entered). While sync is off the jobs wait, so pausing the
 * integration never loses rows.
 */
defined('QMS') || exit;

require_once QMS_ROOT . '/includes/validate.php';

const SHEET_REPORT_UPSERT       = 'REPORT_UPSERT';
const SHEET_OBSERVATIONS_APPEND = 'OBSERVATIONS_APPEND';
const SHEET_STATUSES            = ['PENDING_SYNC', 'PROCESSING', 'SYNCED', 'FAILED'];

function sheet_configured(): bool
{
    return setting_bool('google.sync_enabled') || trim(setting_str('google.spreadsheet_id')) !== '';
}

/** Queues the latest state of a report for the Reports tab (one waiting job per report is enough). */
function sheet_enqueue_report(int $reportId): void
{
    if (! sheet_configured()) {
        return;
    }
    $waiting = (int) db_value("SELECT COUNT(*) FROM google_sheet_sync_logs WHERE report_id = ? AND job_type = ? AND sync_status = 'PENDING_SYNC'",
        [$reportId, SHEET_REPORT_UPSERT]);
    if ($waiting === 0) {
        sheet_insert_job($reportId, SHEET_REPORT_UPSERT, sheet_reports_tab());
    }
}

/** Queues the readings of a final report (approved / rejected) for the monthly observations tab. */
function sheet_enqueue_observations(int $reportId, string $inspectionDate): void
{
    if (! sheet_configured() || sheet_detail_mode() === 'NONE') {
        return;
    }
    $exists = (int) db_value("SELECT COUNT(*) FROM google_sheet_sync_logs WHERE report_id = ? AND job_type = ? AND sync_status <> 'FAILED'",
        [$reportId, SHEET_OBSERVATIONS_APPEND]);
    if ($exists === 0) {
        sheet_insert_job($reportId, SHEET_OBSERVATIONS_APPEND, sheet_observations_tab($inspectionDate));
    }
}

function sheet_detail_mode(): string
{
    $mode = strtoupper(setting_str('google.observation_detail', 'ALL'));

    return in_array($mode, ['ALL', 'OOS_ONLY', 'NONE'], true) ? $mode : 'ALL';
}

function sheet_reports_tab(): string
{
    $name = trim(setting_str('google.reports_sheet', 'Reports'));

    return $name === '' ? 'Reports' : mb_substr($name, 0, 100);
}

function sheet_observations_tab(string $inspectionDate): string
{
    $prefix = trim(setting_str('google.observations_sheet_prefix', 'Observations_'));

    return mb_substr(($prefix === '' ? 'Observations_' : $prefix) . substr($inspectionDate, 0, 4) . '_' . substr($inspectionDate, 5, 2), 0, 100);
}

function sheet_insert_job(int $reportId, string $jobType, string $tab): void
{
    $now = now_str();
    db_insert('google_sheet_sync_logs', ['report_id' => $reportId, 'job_type' => $jobType, 'sheet_name' => $tab,
        'sync_status' => 'PENDING_SYNC', 'attempt_count' => 0, 'next_attempt_at' => $now, 'created_at' => $now]);
}

// ---------------------------------------------------------------- monitor

/** Least advanced sync status of a report's jobs (badge on the report page); null = never queued. */
function sheet_report_state(int $reportId): ?string
{
    $statuses = db_column('SELECT sync_status FROM google_sheet_sync_logs WHERE report_id = ?', [$reportId]);
    foreach (['FAILED', 'PROCESSING', 'PENDING_SYNC', 'SYNCED'] as $status) {
        if (in_array($status, $statuses, true)) {
            return $status;
        }
    }

    return null;
}

/** @return array<string, int> status => count, plus oldest_pending_minutes */
function sheet_summary(): array
{
    $counts = array_fill_keys(SHEET_STATUSES, 0);
    foreach (db_all('SELECT sync_status, COUNT(*) AS n FROM google_sheet_sync_logs GROUP BY sync_status') as $row) {
        $counts[$row['sync_status']] = (int) $row['n'];
    }
    $oldest = db_value("SELECT MIN(created_at) FROM google_sheet_sync_logs WHERE sync_status IN ('PENDING_SYNC', 'PROCESSING')");
    $counts['oldest_pending_minutes'] = $oldest === null ? 0 : max(0, (int) floor((now_utc()->getTimestamp() - strtotime($oldest . ' UTC')) / 60));

    return $counts;
}

/** Reports whose latest data is not in Google Sheets yet (dashboard card). */
function sheet_pending_report_count(): int
{
    return (int) db_value("SELECT COUNT(DISTINCT report_id) FROM google_sheet_sync_logs WHERE sync_status IN ('PENDING_SYNC', 'PROCESSING', 'FAILED')");
}

/** Job list with filters status, job_type, q (report number). */
function sheet_jobs(array $filters, array &$paging): array
{
    $where  = [];
    $params = [];
    if (in_array($filters['status'] ?? '', SHEET_STATUSES, true)) {
        $where[]  = 'j.sync_status = ?';
        $params[] = $filters['status'];
    }
    if (in_array($filters['job_type'] ?? '', [SHEET_REPORT_UPSERT, SHEET_OBSERVATIONS_APPEND], true)) {
        $where[]  = 'j.job_type = ?';
        $params[] = $filters['job_type'];
    }
    if (trim((string) ($filters['q'] ?? '')) !== '') {
        $where[]  = "r.report_no LIKE ? ESCAPE '!'";
        $params[] = db_like(trim((string) $filters['q']));
    }
    $from = ' FROM google_sheet_sync_logs j JOIN inspection_reports r ON r.id = j.report_id JOIN report_types rt ON rt.id = r.report_type_id'
        . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));
    $paging['total'] = (int) db_value('SELECT COUNT(*)' . $from, $params);

    return db_all('SELECT j.*, r.report_no, r.revision_no, r.status AS report_status, rt.code AS type_code' . $from
        . ' ORDER BY j.id DESC LIMIT ? OFFSET ?', [...$params, $paging['per_page'], $paging['offset']]);
}

/** Puts a failed (or stuck) job back in the queue for an immediate attempt. */
function sheet_retry(int $jobId): void
{
    $job = db_row('SELECT * FROM google_sheet_sync_logs WHERE id = ?', [$jobId]) ?? not_found('Sync job not found.');
    if ($job['sync_status'] === 'SYNCED') {
        fail_field('job', 'This job is already synchronised.');
    }
    if ($job['sync_status'] === 'PROCESSING' && $job['locked_at'] !== null && strtotime($job['locked_at'] . ' UTC') > now_utc()->getTimestamp() - 600) {
        fail_field('job', 'The worker is processing this job right now.');
    }
    db_update('google_sheet_sync_logs', ['sync_status' => 'PENDING_SYNC', 'attempt_count' => 0, 'next_attempt_at' => now_str(),
        'locked_at' => null, 'locked_by' => null], 'id = ?', [$jobId]);
    audit_log('SYNC_RETRY', 'sync', $jobId, ['status' => $job['sync_status'], 'attempts' => (int) $job['attempt_count']], ['status' => 'PENDING_SYNC'],
        'report #' . $job['report_id']);
}

function sheet_retry_failed(): int
{
    $count = db_exec("UPDATE google_sheet_sync_logs SET sync_status = 'PENDING_SYNC', attempt_count = 0, next_attempt_at = ?, locked_at = NULL, locked_by = NULL
                       WHERE sync_status = 'FAILED'", [now_str()]);
    audit_log('SYNC_RETRY_ALL', 'sync', null, null, ['jobs' => $count]);

    return $count;
}

/**
 * Queues every numbered report of a date range (integration configured late, or a
 * replaced spreadsheet). The worker de-duplicates rows, so running it twice is safe.
 */
function sheet_backfill(string $from, string $to): int
{
    foreach (['from' => $from, 'to' => $to] as $field => $date) {
        if (! validate_date($date)) {
            fail_field($field, 'Enter the dates as YYYY-MM-DD.');
        }
    }
    if ($to < $from) {
        fail_field('to', 'The end date must be on or after the start date.');
    }
    if (! sheet_configured()) {
        fail_field('google', 'Configure the spreadsheet ID first (Settings → Google Sheets).');
    }
    $reports = db_all('SELECT id, status, inspection_date FROM inspection_reports WHERE report_no IS NOT NULL AND inspection_date BETWEEN ? AND ? ORDER BY id',
        [$from, $to]);
    if (count($reports) > 5000) {
        fail_field('to', 'Choose a shorter period (at most 5,000 reports at a time).');
    }
    db_transaction(static function () use ($reports, $from, $to): void {
        foreach ($reports as $report) {
            sheet_enqueue_report((int) $report['id']);
            if (in_array($report['status'], ['QA_APPROVED', 'REJECTED', 'SUPERSEDED'], true)) {
                sheet_enqueue_observations((int) $report['id'], (string) $report['inspection_date']);
            }
        }
        audit_log('SYNC_BACKFILL', 'sync', null, null, ['from' => $from, 'to' => $to, 'reports' => count($reports)]);
    });

    return count($reports);
}
