<?php
/**
 * Start of every page:   require __DIR__ . '/includes/init.php';
 *
 * Loads the configuration and helpers, connects nothing until needed, sends
 * the security headers, starts the session and checks the CSRF token of every
 * POST request. Pages may define before including it:
 *   QMS_NO_SESSION – no session (health check, logo)
 *   QMS_API        – JSON endpoint (errors as JSON, flash messages untouched)
 *   QMS_INSTALLER  – the setup page (works without config/config.php)
 */
declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('QMS needs PHP 8.1 or newer (this server runs ' . PHP_VERSION . ').');
}

define('QMS', true);
define('QMS_ROOT', dirname(__DIR__));
define('QMS_VERSION', '1.0.0');
define('QMS_CLI', PHP_SAPI === 'cli');

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('zend.exception_ignore_args', '1');

require QMS_ROOT . '/includes/config.php';
require QMS_ROOT . '/includes/errors.php';
require QMS_ROOT . '/includes/db.php';
require QMS_ROOT . '/includes/helpers.php';
require QMS_ROOT . '/includes/constants.php';
require QMS_ROOT . '/includes/settings.php';
require QMS_ROOT . '/includes/clock.php';
require QMS_ROOT . '/includes/audit.php';
require QMS_ROOT . '/includes/auth.php';
require QMS_ROOT . '/includes/csrf.php';

$installed = config_load() && is_file(QMS_ROOT . '/storage/installed.lock');
if (! $installed && ! defined('QMS_INSTALLER')) {
    if (QMS_CLI) {
        fwrite(STDERR, "QMS is not installed yet: open install.php in the browser first.\n");
        exit(1);
    }
    header('Location: ' . url('install.php'));
    exit;
}

if (QMS_CLI) {
    return;
}

// ---------------------------------------------------------------- web request

if (config('force_https', false) && ! is_https()) {
    header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    exit;
}

header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');
header('X-Request-Id: ' . request_id());
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; style-src-attr 'none'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
if (is_https()) {
    header('Strict-Transport-Security: max-age=31536000');
}

if (is_file(QMS_ROOT . '/storage/maintenance.flag') && ! defined('QMS_INSTALLER')) {
    show_error(503, 'The application is being updated. Please try again in a few minutes.');
}

// Invalid UTF-8 or control characters in the input are refused.
$qms_clean = static function (mixed $value) use (&$qms_clean): bool {
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            if (! $qms_clean((string) $k) || ! $qms_clean($v)) {
                return false;
            }
        }

        return true;
    }

    return ! is_string($value) || (mb_check_encoding($value, 'UTF-8') && preg_match('/\A[\r\n\t[:^cntrl:]]*\z/u', $value) === 1);
};
if (! $qms_clean($_GET) || ! $qms_clean($_POST)) {
    show_error(400, 'The request contains invalid characters.');
}
unset($qms_clean);

if (! defined('QMS_NO_SESSION')) {
    $secure = is_https();
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cache_limiter', '');
    ini_set('session.gc_maxlifetime', '43200');
    $sessionDir = QMS_ROOT . '/storage/sessions';
    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        session_save_path($sessionDir);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }
    $basePath = (string) parse_url(base_url(), PHP_URL_PATH);
    session_name($secure ? '__Host-qms' : 'qms_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $secure ? '/' : ($basePath !== '' ? $basePath : '/'),
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    if (! defined('QMS_API')) {
        flash_rotate();
    }

    if (is_post()) {
        // A body larger than post_max_size arrives empty: say so instead of "session expired".
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if ($_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && ! str_contains($contentType, 'json')) {
            show_error(413, 'The upload is larger than the server allows. Please choose a smaller file.');
        }
        // Same-origin check, then the CSRF token.
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
        $sameHost = strcasecmp((string) parse_url($origin, PHP_URL_HOST), (string) parse_url(base_url(), PHP_URL_HOST)) === 0
            && parse_url($origin, PHP_URL_PORT) === parse_url(base_url(), PHP_URL_PORT);
        if ($origin !== '' && $origin !== 'null' && ! $sameHost) {
            show_error(403, 'Request refused: it did not come from this application.');
        }
        csrf_check();
    }
}
