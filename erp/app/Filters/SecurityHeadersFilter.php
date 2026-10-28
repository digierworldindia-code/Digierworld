<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Security headers for every response. Inline <script> is disallowed by CSP
 * (all JS lives in /assets/js); authenticated pages are never cached by the
 * browser so data cannot leak through history/back-button caches.
 */
class SecurityHeadersFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Frame-Options', 'DENY');
        $response->setHeader('Referrer-Policy', 'same-origin');
        $response->setHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->setHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $response->setHeader(
            'Content-Security-Policy',
            // self-hosted assets only: no CDN, no inline script, no inline style, no external font
            "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; "
            . "font-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'; object-src 'none'"
        );
        if ($request instanceof IncomingRequest && $request->isSecure()) {
            $response->setHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if ($this->privatePage($request)) {
            $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $response->setHeader('Pragma', 'no-cache');
        }

        return null;
    }

    /**
     * Signed-in pages and the setup page are never cached. Shield reads its
     * settings from the database, so when that cannot be read (not installed
     * yet, or the database is down) the page is treated as private too
     * instead of failing.
     */
    private function privatePage(RequestInterface $request): bool
    {
        $path = $request instanceof IncomingRequest ? trim($request->getPath(), '/') : '';
        if ($path === 'setup' || str_starts_with($path, 'setup/')) {
            return true; // first-time setup: private, and its tables may not exist yet
        }
        try {
            return function_exists('auth') && auth('session')->loggedIn();
        } catch (\Throwable) {
            return true;
        }
    }
}
