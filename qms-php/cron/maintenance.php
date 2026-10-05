<?php
/**
 * Daily housekeeping. Run it once a day from cron:
 *
 *   15 2 * * * php /path/to/qms/cron/maintenance.php >> /path/to/qms/storage/logs/cron.log 2>&1
 *
 * Removes old idempotency keys, expired session files and log files older
 * than 90 days. Business records and the audit trail are never deleted.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/includes/init.php';
require_once QMS_ROOT . '/includes/idempotency.php';

$say = static fn (string $line) => print(gmdate('Y-m-d H:i:s') . ' ' . $line . "\n");

$say('Idempotency keys removed: ' . idempotency_purge(48));

$removed = 0;
$limit   = time() - 43200; // session lifetime (12 h)
foreach (glob(QMS_ROOT . '/storage/sessions/sess_*') ?: [] as $file) {
    if (is_file($file) && filemtime($file) < $limit && @unlink($file)) {
        $removed++;
    }
}
$say('Expired session files removed: ' . $removed);

$removed = 0;
foreach (glob(QMS_ROOT . '/storage/logs/log-*.php') ?: [] as $file) {
    if (filemtime($file) < time() - 90 * 86400 && @unlink($file)) {
        $removed++;
    }
}
$say('Log files older than 90 days removed: ' . $removed);
