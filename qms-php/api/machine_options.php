<?php
/**
 * JSON: machines on which a part can be inspected for a report type
 * (GET ?type=<report type id>&part=<part id>), used by the "New inspection" page.
 */
define('QMS_API', true);
require dirname(__DIR__) . '/includes/init.php';
$user = require_permission('inspection.create');
require_once QMS_ROOT . '/includes/inspections.php';

try {
    json_out(['ok' => true, 'machines' => insp_machine_options(get_int('type'), get_int('part'))]);
} catch (Throwable $e) {
    json_error($e);
}
