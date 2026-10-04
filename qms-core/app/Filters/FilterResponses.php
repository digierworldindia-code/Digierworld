<?php

declare(strict_types=1);

namespace App\Filters;

use App\Core\Request;
use App\Core\Response;

/**
 * Shared JSON / HTML error responses for filters.
 */
trait FilterResponses
{
    protected function wantsJson(Request $request): bool
    {
        return $request->isAJAX() || str_contains($request->getHeaderLine('Accept'), 'application/json');
    }

    protected function errorResponse(Request $request, int $status, string $message, array $extra = []): Response
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
