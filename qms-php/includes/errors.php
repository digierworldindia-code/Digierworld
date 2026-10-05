<?php
/**
 * Error types and error handling.
 *
 * Business errors are thrown as QmsError (or a subclass) with a message that is
 * safe to show to the user. Anything else is logged with a reference number and
 * the user only sees "Something went wrong. Reference: …".
 */
defined('QMS') || exit;

class QmsError extends RuntimeException
{
    public int $status = 400;

    /** @var array<string, string> field => message */
    public array $errors = [];

    public function __construct(string $message, int $status = 400, array $errors = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errors = $errors;
    }
}

/** Invalid input: field => message. */
class ValidationError extends QmsError
{
    public function __construct(array $errors, string $message = 'Please correct the highlighted fields.')
    {
        parent::__construct($message, 422, $errors);
    }
}

function fail(string $message, int $status = 400): never
{
    throw new QmsError($message, $status);
}

function fail_field(string $field, string $message): never
{
    throw new ValidationError([$field => $message], $message);
}

function not_found(string $message = 'The requested record was not found.'): never
{
    throw new QmsError($message, 404);
}

/**
 * Converts any exception into a QmsError with a safe message. Database guard
 * errors (triggers "QMS-LOCK:", duplicates, constraint failures) get friendly
 * texts; unexpected errors are logged.
 */
function qms_error(Throwable $e): QmsError
{
    if ($e instanceof QmsError) {
        return $e;
    }
    if ($e instanceof PDOException) {
        $code    = (int) ($e->errorInfo[1] ?? 0);
        $message = (string) ($e->errorInfo[2] ?? $e->getMessage());
        if (str_contains($message, 'QMS-LOCK:')) {
            return new QmsError(trim(substr($message, strpos($message, 'QMS-LOCK:') + 9)), 423);
        }
        if ($code === 1062) {
            return new ValidationError([], 'This record already exists (duplicate code or number).');
        }
        if ($code === 3819) {
            return new ValidationError([], 'The values break a data rule (for example LSL greater than USL).');
        }
        if ($code === 1451 || $code === 1452) {
            return new ValidationError([], 'This record is still referenced by other data and cannot be changed this way.');
        }
    }

    $reference = request_id();
    log_message('error', "[{$reference}] " . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
    $message = config('environment') === 'development'
        ? get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'
        : "Something went wrong. Reference: {$reference}";

    return new QmsError($message, 500);
}

/** Random id of this request (shown on error pages, stored in the audit trail). */
function request_id(): string
{
    static $id = null;

    return $id ??= bin2hex(random_bytes(16));
}

/**
 * Appends to storage/logs/log-YYYY-MM-DD.php. The files start with an exit
 * statement, so they show nothing even if the folder were reachable from the web.
 */
function log_message(string $level, string $message): void
{
    $dir  = QMS_ROOT . '/storage/logs';
    $file = $dir . '/log-' . gmdate('Y-m-d') . '.php';
    $line = strtoupper($level) . ' - ' . gmdate('Y-m-d H:i:s') . ' UTC --> '
        . str_replace("\n", "\n    ", (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', ' ', $message)) . "\n";
    if (! is_file($file)) {
        $line = "<?php exit; ?>\n" . $line;
    }
    if (! is_dir($dir) || @file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
        error_log('QMS: ' . $message);
    }
}

/** Shows an error page (or JSON for fetch requests) and stops. */
function show_error(int $status, string $message): never
{
    if (! headers_sent()) {
        http_response_code($status);
        header('Cache-Control: no-store');
    }
    if (QMS_CLI) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
    if (wants_json()) {
        if (! headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }
        echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $titles = [400 => 'Bad request', 401 => 'Please log in', 403 => 'Not allowed', 404 => 'Not found', 409 => 'Changed by someone else',
        413 => 'Too large', 419 => 'Form expired', 422 => 'Please check the input', 423 => 'Record locked', 429 => 'Too many attempts',
        500 => 'Something went wrong', 503 => 'Not available'];
    $title = $titles[$status] ?? 'Error';
    require QMS_ROOT . '/includes/views/error.php';
    exit;
}

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if ((error_reporting() & $severity) === 0) {
        return false; // silenced with @
    }
    if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
        log_message('warning', "Deprecated: {$message} in {$file}:{$line}");

        return true;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    $error = qms_error($e);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    show_error($error->status, $error->getMessage());
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        log_message('critical', "Fatal error: {$error['message']} in {$error['file']}:{$error['line']}");
    }
});
