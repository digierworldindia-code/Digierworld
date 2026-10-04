<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\QmsException;
use Throwable;

/**
 * Turns PHP errors into exceptions and every uncaught exception into a
 * friendly page or JSON body without stack traces. Business errors keep their
 * message and status (QmsException, database "QMS-LOCK:" guards); anything
 * else is logged with a reference id that the user can quote.
 */
final class ErrorHandler
{
    public static function register(): void
    {
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if ((error_reporting() & $severity) === 0) {
            return false; // silenced with @
        }
        if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
            log_message('warning', 'Deprecated: {message} in {file}:{line}', ['message' => $message, 'file' => $file, 'line' => $line]);

            return true;
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    public static function handleException(Throwable $e): void
    {
        if (is_cli()) {
            fwrite(STDERR, PHP_EOL . '[' . $e::class . '] ' . $e->getMessage() . PHP_EOL . 'at ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
            if (ENVIRONMENT !== 'production') {
                fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
            }
            self::describe($e); // logs unexpected errors

            exit(1);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        self::render($e, null)->send();
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null || ! in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }

        try {
            log_message('critical', 'Fatal error: {message} in {file}:{line}', $error);
        } catch (Throwable) {
            error_log('QMS fatal error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        }
        if (! is_cli() && ! headers_sent()) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store');
            echo '<!doctype html><meta charset="utf-8"><title>Error</title><p style="font-family:sans-serif;padding:2rem">Something went wrong. Please try again or contact the administrator.</p>';
        }
    }

    /**
     * Error response for an exception (HTML page or JSON, by Accept header).
     */
    public static function render(Throwable $e, ?Request $request): Response
    {
        [$status, $message] = self::describe($e);

        try {
            $request ??= service('request');
            $wantsJson = $request->wantsJson();
        } catch (Throwable) {
            $wantsJson = false;
        }

        $requestId = self::requestId();
        $response  = new Response();
        $response->setStatusCode($status)
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Request-Id', $requestId);
        if ($status === 405 && $e instanceof HttpException && isset($e->allow)) {
            $response->setHeader('Allow', (string) $e->allow);
        }

        if ($wantsJson) {
            return $response->setJSON(['ok' => false, 'error' => $message]);
        }

        try {
            $body = view('errors/qms_error', ['status' => $status, 'message' => $message, 'requestId' => $requestId], ['saveData' => false]);
        } catch (Throwable) {
            $body = '<!doctype html><meta charset="utf-8"><title>Error ' . $status . '</title><main style="font-family:sans-serif;padding:2rem"><h1>'
                . $status . '</h1><p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></main>';
        }

        return $response->setBody($body);
    }

    /**
     * @return array{0: int, 1: string} HTTP status and a message that is safe to show
     */
    public static function describe(Throwable $e): array
    {
        if ($e instanceof QmsException) {
            return [$e->httpStatus(), $e->getMessage()];
        }
        if ($e instanceof DatabaseException && str_contains($e->getMessage(), 'QMS-LOCK:')) {
            return [423, trim(substr($e->getMessage(), (int) strpos($e->getMessage(), 'QMS-LOCK:') + 9))];
        }

        $reference = self::requestId();
        try {
            log_message('critical', "[{ref}] {class}: {message} at {file}:{line}\n{trace}", [
                'ref' => $reference, 'class' => $e::class, 'message' => $e->getMessage(),
                'file' => $e->getFile(), 'line' => $e->getLine(), 'trace' => $e->getTraceAsString(),
            ]);
        } catch (Throwable) {
            error_log('QMS error [' . $reference . '] ' . $e::class . ': ' . $e->getMessage());
        }

        $message = ENVIRONMENT === 'development'
            ? $e::class . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'
            : "Something went wrong. Reference: {$reference}";

        return [500, $message];
    }

    private static function requestId(): string
    {
        try {
            return service('requestContext')->requestId();
        } catch (Throwable) {
            return bin2hex(random_bytes(8));
        }
    }
}
