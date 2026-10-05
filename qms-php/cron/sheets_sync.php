<?php
/**
 * Sends waiting Google Sheets jobs. Run it every minute from cron:
 *
 *   * * * * * php /path/to/qms/cron/sheets_sync.php --quiet >> /path/to/qms/storage/logs/cron.log 2>&1
 *
 * Options: --limit=40 (jobs per run), --quiet (for cron: one summary line, and only
 * when jobs were sent or failed - nothing when there was nothing to do).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/includes/init.php';
require_once QMS_ROOT . '/includes/sheets_worker.php';

$options = getopt('', ['limit::', 'quiet']);
$limit   = max(1, min(500, (int) ($options['limit'] ?? GS_BATCH_SIZE)));
$quiet   = isset($options['quiet']);

// One run at a time on this server (the worker also takes a MySQL lock).
$lock = fopen(QMS_ROOT . '/storage/sheets-sync.lock', 'c');
if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
    if (! $quiet) {
        echo gmdate('Y-m-d H:i:s') . " another sync run is still busy\n";
    }
    exit(0);
}

$summary = sheet_worker_run($limit, $quiet ? null : static function (string $line): void {
    echo gmdate('Y-m-d H:i:s') . ' ' . $line . "\n";
});
if ($summary['skipped'] !== null) {
    if (! $quiet) {
        echo gmdate('Y-m-d H:i:s') . ' skipped: ' . $summary['skipped'] . "\n";
    }
    exit(0);
}
if (! $quiet || $summary['processed'] > 0) {
    printf("%s processed=%d synced=%d failed=%d\n", gmdate('Y-m-d H:i:s'), $summary['processed'], $summary['synced'], $summary['failed']);
}
