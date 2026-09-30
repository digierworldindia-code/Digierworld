<?php

namespace App\Filters;

use App\Services\AuthService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Requires a signed-in user and loads them into the request context.
 *
 * The identity comes from the server-side session record only. One obligation
 * is enforced before anything else is reachable: a temporary password must be
 * changed. Until then, only the security page and sign-out answer.
 *
 * There is deliberately no second-factor obligation here. It used to redirect
 * any senior role that had not enrolled to the setup page, and because that
 * gate only cleared when an authenticator code was accepted, anything that
 * stopped a code being accepted — a server clock a minute out, a mistyped
 * key, a replaced phone — left the account bounced back to the same page from
 * every single admin screen, with no way out of it from the interface.
 */
class AuthFilter implements FilterInterface
{
    use MarksPrivate;

    private const ALWAYS_ALLOWED = ['account/security', 'account/password', 'logout'];

    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();
        $user    = AuthService::instance()->resolve($session);
        $path    = trim($request->getUri()->getPath(), '/');

        if ($user === null) {
            $session->set('redirect_after_login', current_url());
            $login = str_starts_with($path, 'dealer') ? 'dealer/login' : 'admin/login';

            return $this->private(redirect()->to(site_url($login))->with('notice', 'Please sign in to continue.'));
        }

        service('requestContext')->signIn($user);

        if (in_array($path, self::ALWAYS_ALLOWED, true)) {
            return null;
        }
        if ($user['must_change_password']) {
            return $this->private(redirect()->to(site_url('account/security'))->with('notice', 'You are using a temporary password. Set your own before continuing.'));
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
