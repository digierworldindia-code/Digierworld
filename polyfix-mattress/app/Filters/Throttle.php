<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Polyfix;

/**
 * Rate limits per client address:  'filter' => 'throttle:login'
 *
 * Only state-changing requests (POST) are counted, except the public warranty
 * lookup, which is a GET and is limited because it is the one anonymous route
 * that reads mattress data.
 *
 * The address comes from Request::getIPAddress(), which honours X-Forwarded-For
 * only from proxies listed in app.proxyIPs — so a client cannot rotate a header
 * to reset its own limit.
 */
class Throttle implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $bucket = $arguments[0] ?? 'default';
        $config = config(Polyfix::class);

        [$capacity, $seconds, $methods] = match ($bucket) {
            'login'  => [$config->rateLoginPerMinute, MINUTE, ['POST']],
            'reset'  => [$config->ratePasswordResetPer15m, 15 * MINUTE, ['POST']],
            'form'   => [$config->ratePublicFormPerHour, HOUR, ['POST']],
            'verify' => [$config->rateVerifyPerMinute, MINUTE, ['GET', 'POST']],
            default  => [120, MINUTE, ['POST']],
        };

        if (! in_array($request->getMethod(), $methods, true)) {
            return null;
        }

        $key = 'throttle_' . $bucket . '_' . md5((string) $request->getIPAddress());
        if (service('throttler')->check($key, $capacity, $seconds)) {
            return null;
        }

        log_message('notice', 'security.RATE_LIMITED bucket={bucket}', ['bucket' => $bucket]);
        $wait = max(1, service('throttler')->getTokenTime());

        return service('response')
            ->setStatusCode(429)
            ->setHeader('Retry-After', (string) $wait)
            ->setBody(view('errors/html/error_429', ['wait' => $wait]));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
