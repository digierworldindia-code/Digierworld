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
 * A permission is granted by role alone, so a refusal here means the account
 * simply does not hold it.
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

        log_message('notice', 'security.PERMISSION_REFUSED user={user} needs={perm}', [
            'user' => $context->userId() ?? 'anonymous', 'perm' => implode('|', $permissions),
        ]);

        $message = 'You do not have permission to do that.';

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
