<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Security headers on every response (CSP is added by CodeIgniter's CSP class,
 * HSTS by Nginx / forceGlobalSecureRequests). Also sets X-Request-Id.
 */
class SecurityHeadersFilter implements FilterInterface
{
    private const HEADERS = [
        'X-Content-Type-Options'            => 'nosniff',
        'X-Frame-Options'                   => 'DENY',
        'Referrer-Policy'                   => 'same-origin',
        'Permissions-Policy'                => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        'Cross-Origin-Opener-Policy'        => 'same-origin',
        'X-Permitted-Cross-Domain-Policies' => 'none',
    ];

    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        foreach (self::HEADERS as $name => $value) {
            $response->setHeader($name, $value);
        }
        $response->setHeader('X-Request-Id', service('requestContext')->requestId());

        return $response;
    }
}
