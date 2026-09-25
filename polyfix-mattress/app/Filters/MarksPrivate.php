<?php

namespace App\Filters;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * A filter that turns a request away still has to mark its answer private.
 *
 * When a before-filter returns a response, the after-filters never run — so the
 * redirect to the sign-in page would otherwise be the one response in a private
 * area with no noindex header and no cache rule.
 */
trait MarksPrivate
{
    protected function private(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->setHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->setHeader('Cache-Control', 'no-store, private');
    }
}
