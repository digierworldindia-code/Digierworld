<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Clears per-request caches held by shared services (important for
 * long-running workers and for feature tests that issue many requests).
 */
class RequestContextFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        service('visibility')->reset();
        service('companyContext')->flush();
        service('entitlements')->flush();
        service('settings_store')->flush();

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
