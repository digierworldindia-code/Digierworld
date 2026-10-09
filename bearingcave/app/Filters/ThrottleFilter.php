<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Proportional rate limiting for form submissions (login, registration,
 * password recovery, contact). Only POST requests are counted, per IP and
 * route, so legitimate users are slowed for a minute — never locked out.
 */
class ThrottleFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (strtolower($request->getMethod()) !== 'post') {
            return null;
        }
        $limit = (int) ($arguments[0] ?? (ENVIRONMENT === 'testing' ? 1000 : 10));
        $key   = 'thr_' . md5($request->getIPAddress() . '|' . $request->getUri()->getPath());
        if (service('throttler')->check($key, $limit, MINUTE) === false) {
            service('audit')->security('rate_limited', 'Too many submissions to ' . $request->getUri()->getPath());

            return service('response')->setStatusCode(429)->setBody(view('errors/rate_limited', ['seconds' => service('throttler')->getTokenTime()]));
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
