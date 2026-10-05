<?php
/**
 * Company logo (stored outside the web root in storage/uploads/logo).
 */
define('QMS_NO_SESSION', true);
require __DIR__ . '/includes/init.php';
require_once QMS_ROOT . '/includes/uploads.php';

$file = upload_path('logo', setting_str('company.logo'));
if ($file === null) {
    show_error(404, 'No logo has been uploaded.');
}
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . filesize($file));
readfile($file);
