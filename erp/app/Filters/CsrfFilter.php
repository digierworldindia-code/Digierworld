<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Security\Exceptions\SecurityException;
use Config\Cookie;
use Config\Session;

/**
 * CodeIgniter's CSRF check, plus a clear outcome when it fails.
 *
 * The token lives in the session. If the browser never sends the session
 * cookie back (cookie.secure on an http:// address, an address that does
 * not match app.baseURL, cookies blocked), every sign-in fails the check and
 * CodeIgniter's "redirect back with an error" cannot help: the error is
 * stored in that same missing session, so the sign-in page just reappears.
 * That case goes to the sign-in page with ?session=missing, which explains
 * it without needing the session. Other failures (form left open too long)
 * go back with the message.
 */
class CsrfFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! $request instanceof IncomingRequest) {
            return null;
        }
        $security = service('security');

        try {
            $security->verify($request);
        } catch (SecurityException $e) {
            if ($request->isAJAX() || ! $security->shouldRedirect()) {
                throw $e;
            }
            $cookie = config(Cookie::class)->prefix . config(Session::class)->cookieName;
            if ($request->getCookie($cookie) === null) {
                log_message('warning', 'CSRF check failed because the browser sent no session cookie ({cookie}); see the sign-in page message.', ['cookie' => $cookie]);

                return redirect()->to(site_url('login') . '?session=missing');
            }

            return redirect()->back()->with('error', lang('Security.disallowedAction')); // no withInput(): it would keep the typed password in the session
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
