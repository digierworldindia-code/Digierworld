<?php

namespace App\Controllers;

use App\Libraries\Audit;
use App\Libraries\Labels;
use App\Services\AuthService;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * The signed-in person's own password, optional two-factor and sessions.
 * Staff and dealers share
 * it; only the surrounding layout differs.
 */
class Account extends BaseController
{
    public function security(): string
    {
        $this->response->setHeader('Cache-Control', 'no-store');
        $user  = $this->ctx->user();
        $setup = session('mfa_setup');

        return view('account/security', [
            'layout'      => $this->ctx->isDealer() ? 'layouts/portal' : 'layouts/console',
            'title'       => 'Password & security',
            'user'        => $user,
            'sessions'    => AuthService::instance()->activeSessions($user['id']),
            'twoFactorOn' => AuthService::twoFactorAvailable(),
            'setup'       => is_array($setup) ? $setup + ['qr' => Labels::qrSvgFor($setup['otpauth'], 4)] : null,
            'codes'       => session()->getFlashdata('recovery_codes'),
        ]);
    }

    public function changePassword(): RedirectResponse
    {
        $new = (string) $this->request->getPost('new_password');
        if ($new !== (string) $this->request->getPost('new_password_confirm')) {
            return redirect()->back()->with('error', 'The two new passwords do not match.');
        }

        return $this->act(
            fn () => AuthService::instance()->changePassword($this->ctx->userId(), (string) $this->request->getPost('current_password'), $new, $this->ctx->sessionId()),
            'Your password has been changed. Every other session has been signed out.',
            site_url('account/security'),
        );
    }

    /**
     * Two-factor is off for the whole installation unless an administrator has
     * turned it on under Settings → Security.
     *
     * Switching it off is deliberately NOT guarded: if the feature is turned
     * off centrally while someone is enrolled, they must still be able to
     * clear their own enrolment.
     */
    private function requireTwoFactorAvailable(): ?RedirectResponse
    {
        return AuthService::twoFactorAvailable()
            ? null
            : redirect()->to(site_url('account/security'))
                ->with('error', 'Two-factor authentication is switched off for this installation. An administrator can turn it on under Settings → Security.');
    }

    public function startMfa(): RedirectResponse
    {
        if (($stop = $this->requireTwoFactorAvailable()) !== null) {
            return $stop;
        }

        return $this->act(function () {
            $setup = AuthService::instance()->startMfaEnrolment($this->ctx->userId(), (string) $this->request->getPost('password'));
            session()->set('mfa_setup', ['secret' => $setup['secret'], 'otpauth' => $setup['otpauth']]);
        }, 'Scan the code with your authenticator app, then enter the 6-digit code it shows.', site_url('account/security'));
    }

    public function confirmMfa(): RedirectResponse
    {
        if (($stop = $this->requireTwoFactorAvailable()) !== null) {
            return $stop;
        }

        return $this->act(function () {
            $codes = AuthService::instance()->confirmMfaEnrolment($this->ctx->userId(), trim((string) $this->request->getPost('code')));
            session()->remove('mfa_setup');
            session()->setFlashdata('recovery_codes', $codes);
        }, 'Two-factor authentication is on. Save your recovery codes now — they are shown only once.', site_url('account/security'));
    }

    public function disableMfa(): RedirectResponse
    {
        return $this->act(
            fn () => AuthService::instance()->disableMfa($this->ctx->userId(), (string) $this->request->getPost('password'), trim((string) $this->request->getPost('code'))),
            'Two-factor authentication is off.',
            site_url('account/security'),
        );
    }

    public function revokeOthers(): RedirectResponse
    {
        return $this->act(function () {
            $n = AuthService::instance()->revokeAllSessions($this->ctx->userId(), 'signed out by user', $this->ctx->sessionId());
            Audit::instance()->record('SESSIONS_REVOKED', 'user', $this->ctx->userId(), null, ['count' => $n]);

            return $n;
        }, static fn ($n) => $n === 0 ? 'There were no other sessions.' : "Signed out {$n} other session(s).", site_url('account/security'));
    }
}
