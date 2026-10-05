<?php
/**
 * JSON submission of a report (POST ?id=<report id>, header Idempotency-Key).
 * Sent by the entry screen after the last autosave; a repeated request with the
 * same key returns the first answer instead of submitting twice.
 */
define('QMS_API', true);
require dirname(__DIR__) . '/includes/init.php';
$user = require_permission('inspection.submit');
require_once QMS_ROOT . '/includes/workflow.php';

try {
    if (! is_post()) {
        fail('Use POST.', 405);
    }
    $data   = json_input();
    $result = wf_submit(get_int('id'), is_string($data['seen_status'] ?? null) ? $data['seen_status'] : '', idempotency_key_from_request($data), $user);
    flash('success', $result['message']);
    json_out($result + ['redirect' => url('inspection.php?id=' . (int) $result['report_id'])]);
} catch (Throwable $e) {
    json_error($e);
}
