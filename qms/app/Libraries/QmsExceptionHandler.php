<?php

namespace App\Libraries;

use App\Exceptions\QmsException;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Renders expected business errors (QmsException) and database lock errors
 * (QMS-LOCK triggers) as friendly pages / JSON with the right status code,
 * in every environment, without stack traces.
 */
class QmsExceptionHandler implements ExceptionHandlerInterface
{
    public function handle(Throwable $exception, RequestInterface $request, ResponseInterface $response, int $statusCode, int $exitCode): void
    {
        [$status, $message] = $exception instanceof QmsException
            ? [$exception->httpStatus(), $exception->getMessage()]
            : [423, trim(substr($exception->getMessage(), (int) strpos($exception->getMessage(), 'QMS-LOCK:') + 9))];

        $requestId = service('requestContext')->requestId();
        $response->setStatusCode($status)
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('X-Frame-Options', 'DENY')
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Request-Id', $requestId);

        $wantsJson = method_exists($request, 'isAJAX')
            && ($request->isAJAX() || str_contains($request->getHeaderLine('Accept'), 'application/json'));

        if ($wantsJson) {
            $response->setJSON(['ok' => false, 'error' => $message]);
        } else {
            $response->setBody(view('errors/qms_error', ['status' => $status, 'message' => $message, 'requestId' => $requestId]));
        }

        $response->send();
    }
}
