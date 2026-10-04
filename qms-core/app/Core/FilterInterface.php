<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Route middleware. before() may return a Response to stop the request
 * (redirect to login, 403 …); after() may change the response.
 */
interface FilterInterface
{
    /**
     * @param list<string>|null $arguments values after "name:" in the route filter
     */
    public function before(Request $request, ?array $arguments = null): ?Response;

    /**
     * @param list<string>|null $arguments
     */
    public function after(Request $request, Response $response, ?array $arguments = null): ?Response;
}
