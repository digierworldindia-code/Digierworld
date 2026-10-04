<?php

declare(strict_types=1);

/*
 * Loads the application: paths, class autoloading, .env, error handling and
 * helper functions. Used by public/index.php, the bin/qms.php command line
 * tool and the test-suite.
 */

if (PHP_VERSION_ID < 80200) {
    header('HTTP/1.1 503 Service Unavailable', true, 503);
    echo 'QMS needs PHP 8.2 or newer (this server runs ' . PHP_VERSION . ').';
    exit(1);
}

define('ROOTPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('APPPATH', ROOTPATH . 'app' . DIRECTORY_SEPARATOR);
defined('FCPATH') || define('FCPATH', ROOTPATH . 'public' . DIRECTORY_SEPARATOR);
define('WRITEPATH', ROOTPATH . 'storage' . DIRECTORY_SEPARATOR);

const SECOND = 1;
const MINUTE = 60;
const HOUR   = 3600;
const DAY    = 86400;

// Application classes: App\Foo\Bar → app/Foo/Bar.php
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = APPPATH . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// Third-party libraries (mPDF) installed by Composer or shipped in the release zip.
if (is_file(ROOTPATH . 'vendor/autoload.php')) {
    require ROOTPATH . 'vendor/autoload.php';
}

App\Core\Env::load(ROOTPATH . '.env');

defined('ENVIRONMENT') || define('ENVIRONMENT', in_array(App\Core\Env::get('APP_ENV'), ['development', 'testing'], true) ? App\Core\Env::get('APP_ENV') : 'production');

date_default_timezone_set('UTC'); // stored times are UTC; the plant time zone is a setting
mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', ENVIRONMENT === 'production' ? '0' : '1');
ini_set('log_errors', '1');
ini_set('zend.exception_ignore_args', '1'); // no argument values (passwords) in stack traces
ini_set('default_charset', 'UTF-8');

require APPPATH . 'Core/helpers.php';
require APPPATH . 'Helpers/qms_helper.php';

App\Core\ErrorHandler::register();
