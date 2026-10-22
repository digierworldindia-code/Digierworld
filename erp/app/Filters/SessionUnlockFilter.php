<?php

namespace App\Filters;

use App\Libraries\SessionLock;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Downloads (PDFs, exports, stored files) only read the session, so they
 * release its lock up front: a slow download no longer holds up the user's
 * other tabs. Sign-in and permission checks have already run.
 */
class SessionUnlockFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if ($request->getMethod() === 'GET') {
            SessionLock::release();
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
