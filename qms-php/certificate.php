<?php
/**
 * Download of a calibration certificate (certificate.php?id=<calibration id>).
 * Files live in storage/uploads and are only sent through this page.
 */
require __DIR__ . '/includes/init.php';
$user = require_permission('gauge.view');
require_once QMS_ROOT . '/includes/gauges.php';

$file = gauge_certificate(get_int('id'));
audit_log('DOWNLOAD', 'gauge_certificate', get_int('id'), null, null, $file['name']);

header('Content-Type: ' . (in_array($file['mime'], UPLOAD_CERTIFICATE_MIMES, true) ? $file['mime'] : 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . $file['name'] . '"');
header('Content-Length: ' . filesize($file['path']));
header('Cache-Control: private, no-store');
header("Content-Security-Policy: default-src 'none'; sandbox");
readfile($file['path']);
