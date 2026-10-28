<?php

namespace App\Libraries;

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use mysqli_sql_exception;
use Throwable;

/**
 * On a brand-new installation the database answers but holds no DGR Office
 * tables yet, so every page fails (signing in needs those tables). Instead
 * of the error page, page requests then go to the setup page (/setup). The
 * framework has already logged the exception; every other case keeps the
 * default handler.
 */
final class SetupRedirectHandler implements ExceptionHandlerInterface
{
    public static function applies(Throwable $exception): bool
    {
        $request = service('request');
        if (is_cli() || ! $request instanceof IncomingRequest || $request->getMethod() !== 'GET') {
            return false;
        }
        $path = trim($request->getPath(), '/');
        if ($path === 'setup' || str_starts_with($path, 'setup/')) {
            return false;
        }
        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof DatabaseException || $e instanceof mysqli_sql_exception) {
                try {
                    return service('setup')->tablesMissing();
                } catch (Throwable) {
                    return false;
                }
            }
        }

        return false;
    }

    public function handle(Throwable $exception, RequestInterface $request, ResponseInterface $response, int $statusCode, int $exitCode): void
    {
        $response->redirect(site_url('setup'))->send();
    }
}
