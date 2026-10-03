<?php

namespace App\Filters;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Shared JSON / HTML error responses for filters.
 */
trait FilterResponses
{
    protected function wantsJson(RequestInterface $request): bool
    {
        return $request->isAJAX() || str_contains($request->getHeaderLine('Accept'), 'application/json');
    }

    protected function errorResponse(RequestInterface $request, int $status, string $message, array $extra = []): ResponseInterface
    {
        $response = service('response')->setStatusCode($status);

        if ($this->wantsJson($request)) {
            return $response->setJSON(['ok' => false, 'error' => $message] + $extra);
        }

        return $response->setBody(view('errors/qms_error', [
            'status'    => $status,
            'message'   => $message,
            'requestId' => service('requestContext')->requestId(),
        ]));
    }
}
