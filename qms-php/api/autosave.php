<?php
/**
 * JSON autosave of the inspection entry screen (POST ?id=<report id>):
 * changed cells, gauges, remarks, round details and header in one request.
 * The answer carries the server's verdicts, totals and remaining problems.
 */
define('QMS_API', true);
require dirname(__DIR__) . '/includes/init.php';
$user = require_permission('inspection.create');
require_once QMS_ROOT . '/includes/inspections.php';

try {
    if (! is_post()) {
        fail('Use POST.', 405);
    }
    $payload = json_input();
    if ($payload === []) {
        fail_field('payload', 'The request could not be read. Reload the page.');
    }
    json_out(insp_save_draft(get_int('id'), $payload, $user));
} catch (Throwable $e) {
    json_error($e);
}
