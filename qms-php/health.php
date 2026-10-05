<?php
/**
 * Health check for uptime monitoring: {"status":"ok"} when PHP and MySQL work.
 */
define('QMS_NO_SESSION', true);
define('QMS_API', true);
require __DIR__ . '/includes/init.php';

try {
    db_value('SELECT 1');
    json_out(['status' => 'ok']);
} catch (Throwable) {
    json_out(['status' => 'unavailable'], 503);
}
