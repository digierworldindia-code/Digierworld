<?php

/*
 * Router for PHP's built-in web server - local development only:
 *
 *   php -S 127.0.0.1:8080 -t public bin/dev-router.php
 *
 * Files under public/ are served as they are; everything else goes to the
 * front controller. Production uses Apache (.htaccess) or Nginx.
 */

$public = realpath(__DIR__ . '/../public');
$path   = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file   = realpath($public . $path);

if ($path !== '/' && $file !== false && is_file($file) && str_starts_with($file, $public . DIRECTORY_SEPARATOR) && ! str_ends_with($file, '.php')) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
require $public . '/index.php';
