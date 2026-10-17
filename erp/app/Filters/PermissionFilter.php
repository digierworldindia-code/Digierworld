<?php

namespace App\Filters;

use App\Exceptions\AccessDeniedException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Route-level permission gate: `perm:key1,key2` grants access if the user
 * holds ANY listed key. Controllers/services re-check record scope.
 */
class PermissionFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $perms = array_values(array_filter(array_map('trim', (array) $arguments)));
        if ($perms === []) {
            // a perm filter with no keys is a configuration error → deny
            throw new AccessDeniedException();
        }
        service('authz')->authorize($perms);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
