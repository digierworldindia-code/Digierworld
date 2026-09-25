<?php

namespace App\Controllers;

use App\Exceptions\AppException;
use App\Libraries\Mailer;
use App\Services\AuthService;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Sign-in, two-factor, password reset and sign-out — for staff and dealers
 * alike. The two login addresses differ only in wording; where someone lands
 * afterwards depends on the account, not on the form they used.
 *
 * Every failure reads the same ("not recognised"), whichever of email or
 * password was wrong, and the timing is equalised in AuthService.
 */
class Auth extends BaseController
{
    public function login(string $area = 'admin'): string|RedirectResponse
    {
        $user = AuthService::instance()->resolve(session());
        if ($user !== null) {
            return redirect()->to($this->home($user));
        }
        $this->response->setHeader('Cache-Control', 'no-store');

        return view('auth/login', ['area' => $area === 'dealer' ? 'dealer' : 'admin']);
    }

    public function attempt(string $area = 'admin'): RedirectResponse
    {
        $in = $this->validated([
            'email'    => 'required|max_length[255]',
            'password' => 'required|max_length[200]',
        ]);
        if ($in instanceof RedirectResponse) {
            return $in;
        }

        try {
            $result = AuthService::instance()->attemptPassword($in['email'], $this->request->getPost('password'), session());
        } catch (AppException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        if ($result['status'] === 'mfa_required') {
            return redirect()->to(site_url('login/verify'));
        }

        return $this->afterSignIn();
    }

    public function secondFactor(): string|RedirectResponse
    {
        $pending = session('auth_pending');
        if (! is_array($pending) || ($pending['expires'] ?? 0) < time()) {
            return redirect()->to(site_url('admin/login'))->with('notice', 'Enter your email and password to sign in.');
        }
        $this->response->setHeader('Cache-Control', 'no-store');

        return view('auth/second_factor');
    }

    public function verifySecondFactor(): RedirectResponse
    {
        $code = trim((string) $this->request->getPost('code'));
        if ($code === '' || mb_strlen($code) > 20) {
            return redirect()->back()->with('error', 'Enter the 6-digit code from your authenticator app, or a recovery code.');
        }

        try {
            AuthService::instance()->attemptSecondFactor($code, session());
        } catch (AppException $e) {
            // An expired pending sign-in goes back to the start; a wrong code stays.
            return session('auth_pending') === null
                ? redirect()->to(site_url('admin/login'))->with('error', $e->getMessage())
                : redirect()->back()->with('error', $e->getMessage());
        }

        return $this->afterSignIn();
    }

    public function forgot(): string
    {
        $this->response->setHeader('Cache-Control', 'no-store');

        return view('auth/forgot');
    }

    public function sendReset(): RedirectResponse
    {
        $email = trim((string) $this->request->getPost('email'));
        if ($email !== '' && mb_strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $token = AuthService::instance()->requestPasswordReset($email);
            if ($token !== null) {
                $minutes = (int) (config('Polyfix')->passwordResetTtlSeconds / 60);
                Mailer::send(
                    strtolower($email),
                    'Reset your ' . brand('shortName') . ' password',
                    "Someone asked to reset the password for this account.\n\n"
                    . 'Open this link within ' . $minutes . " minutes to choose a new password:\n"
                    . site_url('reset-password') . '?token=' . rawurlencode($token) . "\n\n"
                    . "If it was not you, ignore this email — your password has not changed.\n\n"
                    . brand('name'),
                );
            }
        }

        // The same answer either way: whether an address is registered is not
        // something an anonymous visitor gets to find out.
        return redirect()->to(site_url('forgot-password'))->with('success', 'If that email address has an account, a reset link is on its way. '
            . 'The link is valid for ' . (int) (config('Polyfix')->passwordResetTtlSeconds / 60) . ' minutes.');
    }

    public function reset(): string
    {
        $this->response->setHeader('Cache-Control', 'no-store');
        $this->response->setHeader('Referrer-Policy', 'no-referrer'); // the token is in the URL

        $token = (string) $this->request->getGet('token');

        return view('auth/reset', ['token' => preg_match('/^[A-Za-z0-9_-]{20,100}$/', $token) ? $token : '']);
    }

    public function doReset(): RedirectResponse
    {
        $token    = (string) $this->request->getPost('token');
        $password = (string) $this->request->getPost('password');
        if ($password !== (string) $this->request->getPost('password_confirm')) {
            return redirect()->back()->with('error', 'The two passwords do not match.');
        }

        try {
            AuthService::instance()->resetPassword($token, $password);
        } catch (AppException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/login'))->with('success', 'Your password has been changed. Sign in with the new one.');
    }

    public function logout(): RedirectResponse
    {
        $wasDealer = $this->ctx->isDealer() || (AuthService::instance()->resolve(session())['dealer_id'] ?? null) !== null;
        AuthService::instance()->signOut(session());

        return redirect()->to(site_url($wasDealer ? 'dealer/login' : 'admin/login'))->with('success', 'You have signed out.');
    }

    private function afterSignIn(): RedirectResponse
    {
        $user   = AuthService::instance()->resolve(session());
        $target = session('redirect_after_login');
        session()->remove('redirect_after_login');

        // Only ever follow a same-site address, and only into the area this
        // account belongs to.
        $home = $this->home($user);
        if (is_string($target) && str_starts_with($target, rtrim(site_url('/'), '/') . '/')
            && str_starts_with($target, $home)) {
            return redirect()->to($target);
        }

        return redirect()->to($home);
    }

    private function home(?array $user): string
    {
        return site_url($user !== null && $user['dealer_id'] !== null ? 'dealer' : 'admin');
    }
}
