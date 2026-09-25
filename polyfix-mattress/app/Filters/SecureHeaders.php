<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Polyfix;

/**
 * Response headers applied to every page.
 *
 * Content-Security-Policy is strict for scripts: 'self' only, no inline script
 * anywhere in the application (every behaviour lives in public/assets/js), so an
 * injected <script> cannot run. Styles allow inline attributes, which Bootstrap
 * components and progress bars need; that is a far smaller risk than script.
 * Analytics hosts are added only when a GA4 id is configured.
 */
class SecureHeaders implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $ga = config(Polyfix::class)->ga4MeasurementId !== '';

        $csp = [
            "default-src 'self'",
            "script-src 'self'" . ($ga ? ' https://www.googletagmanager.com' : ''),
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:" . ($ga ? ' https://www.google-analytics.com https://www.googletagmanager.com' : ''),
            "font-src 'self'",
            "connect-src 'self'" . ($ga ? ' https://www.google-analytics.com https://region1.google-analytics.com' : ''),
            "media-src 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ];
        if (ENVIRONMENT === 'production') {
            $csp[] = 'upgrade-insecure-requests';
            $response->setHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains; preload');
        }

        $response->setHeader('Content-Security-Policy', implode('; ', $csp));
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Frame-Options', 'DENY');
        $response->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->setHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $response->setHeader('Permissions-Policy', 'accelerometer=(), camera=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()');
        $response->removeHeader('X-Powered-By');

        return $response;
    }
}
