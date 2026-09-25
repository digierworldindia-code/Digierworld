<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Route-level permission check:  'filter' => 'can:claim.decide'
 *
 * Permission keys contain colons (claim:decide), but CodeIgniter splits a
 * filter's arguments on EVERY colon, so 'can:claim:decide' would silently
 * check for a permission named 'claim'. Arguments are therefore written with
 * dots and converted back here. Several arguments mean "any of these".
 *
 * For a two-factor-gated permission the role alone is not enough: the session
 * must have satisfied two-factor authentication. The refusal says so plainly.
 */
class PermissionFilter implements FilterInterface
{
    use MarksPrivate;

    public function before(RequestInterface $request, $arguments = null)
    {
        $context     = service('requestContext');
        $permissions = array_map(static fn (string $a) => str_replace('.', ':', $a), (array) $arguments);

        foreach ($permissions as $permission) {
            if ($context->can($permission)) {
                return null;
            }
        }

        $needsMfa = false;
        foreach ($permissions as $permission) {
            $needsMfa = $needsMfa || $context->hasRolePermission($permission);
        }

        log_message('notice', 'security.PERMISSION_REFUSED user={user} needs={perm}', [
            'user' => $context->userId() ?? 'anonymous', 'perm' => implode('|', $permissions),
        ]);

        $message = $needsMfa
            ? 'This action needs two-factor authentication. Turn it on under Security, then sign in again.'
            : 'You do not have permission to do that.';

        if ($request->getMethod() === 'GET') {
            return $this->private(service('response')->setStatusCode(403)->setBody(view('errors/html/error_403', ['message' => $message])));
        }

        return $this->private(redirect()->back()->with('error', $message));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
