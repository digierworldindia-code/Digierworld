<?php

declare(strict_types=1);

namespace App\Filters;

use App\Core\FilterInterface;
use App\Core\Request;
use App\Core\Response;

/** Authenticated pages and JSON must never be cached by browsers or proxies. */
class NoStoreFilter implements FilterInterface
{
    public function before(Request $request, ?array $arguments = null): ?Response
    {
        return null;
    }

    public function after(Request $request, Response $response, ?array $arguments = null): ?Response
    {
        $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->setHeader('Pragma', 'no-cache');

        return $response;
    }
}
