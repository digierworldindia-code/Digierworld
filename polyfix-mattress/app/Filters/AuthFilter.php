<?php

namespace App\Filters;

use App\Services\AuthService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Requires a signed-in user and loads them into the request context.
 *
 * The identity comes from the server-side session record only. Two obligations
 * are enforced before anything else is reachable:
 *   - a temporary password must be changed
 *   - a role that requires two-factor authentication must enrol in it
 * Until then, only the security page and sign-out answer.
 */
class AuthFilter implements FilterInterface
{
    private const ALWAYS_ALLOWED = ['account/security', 'account/password', 'account/mfa/start', 'account/mfa/confirm', 'logout'];

    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();
        $user    = AuthService::instance()->resolve($session);
        $path    = trim($request->getUri()->getPath(), '/');

        if ($user === null) {
            $session->set('redirect_after_login', current_url());
            $login = str_starts_with($path, 'dealer') ? 'dealer/login' : 'admin/login';

            return redirect()->to(site_url($login))->with('notice', 'Please sign in to continue.');
        }

        service('requestContext')->signIn($user);

        if (in_array($path, self::ALWAYS_ALLOWED, true)) {
            return null;
        }
        if ($user['must_change_password']) {
            return redirect()->to(site_url('account/security'))->with('notice', 'You are using a temporary password. Set your own before continuing.');
        }
        if ($user['must_enrol_mfa']) {
            return redirect()->to(site_url('account/security?enrol=1'))->with('notice', 'Your role requires two-factor authentication. Set it up to continue.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
