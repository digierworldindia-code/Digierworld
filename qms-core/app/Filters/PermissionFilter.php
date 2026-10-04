<?php

declare(strict_types=1);

namespace App\Filters;

use App\Core\FilterInterface;
use App\Core\Request;
use App\Core\Response;

/**
 * Route permission check: permission:code1,code2 = the user needs at least one.
 * Denied attempts are written to the audit trail.
 */
class PermissionFilter implements FilterInterface
{
    use FilterResponses;

    public function before(Request $request, ?array $arguments = null): ?Response
    {
        $permissions = array_values(array_filter((array) $arguments));
        $authz       = service('authorization');

        if ($permissions === [] || ! $authz->can(...$permissions)) {
            service('audit')->log('ACCESS_DENIED', 'security', null, null, [
                'required' => implode(',', $permissions),
                'path'     => '/' . ltrim($request->getUri()->getPath(), '/'),
                'method'   => $request->getMethod(),
            ]);

            return $this->errorResponse($request, 403, 'You do not have permission to open this page or perform this action.');
        }

        return null;
    }

    public function after(Request $request, Response $response, ?array $arguments = null): ?Response
    {
        return null;
    }
}
