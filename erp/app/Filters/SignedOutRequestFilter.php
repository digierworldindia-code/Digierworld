<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Runs before Shield's `session` filter. A signed-out request that is not a
 * page visit (an image, a script call, an expired page polling) gets a plain
 * 401 instead of the sign-in redirect, so Shield never records it as the
 * page to return to after sign-in. Page visits are untouched: they still go
 * to the sign-in page and come back to the page asked for.
 */
class SignedOutRequestFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! $request instanceof IncomingRequest || auth('session')->loggedIn()) {
            return null;
        }
        $dest = strtolower($request->getHeaderLine('Sec-Fetch-Dest'));
        if (! $request->isAJAX() && ($dest === '' || in_array($dest, ['document', 'iframe', 'frame'], true))) {
            return null;
        }

        return service('response')->setStatusCode(401)->setJSON(['message' => 'Your session has ended. Please sign in again.']);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
