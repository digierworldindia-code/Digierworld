<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Qms;

/**
 * Rate limit for POST /login per client IP (per minute and per hour buckets).
 */
class LoginThrottleFilter implements FilterInterface
{
    use FilterResponses;

    public function before(RequestInterface $request, $arguments = null)
    {
        if ($request->getMethod() !== 'POST') {
            return null;
        }

        $config    = config(Qms::class);
        $throttler = service('throttler');
        $key       = 'login_' . md5((string) $request->getIPAddress());

        $minuteOk = $throttler->check($key . '_m', $config->loginAttemptsPerMinute, MINUTE);
        $hourOk   = $minuteOk && $throttler->check($key . '_h', $config->loginAttemptsPerHour, HOUR);

        if (! $minuteOk || ! $hourOk) {
            db_connect()->table('login_logs')->insert([
                'user_id'            => null,
                'username_attempted' => mb_substr((string) $request->getPost('username'), 0, 100),
                'event'              => 'LOGIN_FAILED',
                'failure_reason'     => 'THROTTLED',
                'ip_address'         => mb_substr((string) $request->getIPAddress(), 0, 45),
                'user_agent'         => mb_substr($request->getHeaderLine('User-Agent'), 0, 255),
                'created_at'         => service('clock')->nowUtcString(),
            ]);

            return $this->errorResponse($request, 429, 'Too many login attempts from this device. Please wait a few minutes and try again.')
                ->setHeader('Retry-After', '60');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
