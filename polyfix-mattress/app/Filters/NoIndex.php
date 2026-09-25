<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Keeps private pages — admin, dealer portal, sign-in, account — out of search
 * engines, and out of any shared cache. The header works even for responses
 * that are not HTML (exports, media, QR images).
 */
class NoIndex implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->setHeader('Cache-Control', 'no-store, private');

        return $response;
    }
}
