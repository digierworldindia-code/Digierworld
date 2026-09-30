<?php

namespace App\Controllers;

use App\Libraries\Audit;
use App\Services\AuthService;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * The signed-in person's own password and sessions. Staff and dealers share
 * it; only the surrounding layout differs.
 */
class Account extends BaseController
{
    public function security(): string
    {
        $this->response->setHeader('Cache-Control', 'no-store');
        $user = $this->ctx->user();

        return view('account/security', [
            'layout'   => $this->ctx->isDealer() ? 'layouts/portal' : 'layouts/console',
            'title'    => 'Password & security',
            'user'     => $user,
            'sessions' => AuthService::instance()->activeSessions($user['id']),
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

    public function revokeOthers(): RedirectResponse
    {
        return $this->act(function () {
            $n = AuthService::instance()->revokeAllSessions($this->ctx->userId(), 'signed out by user', $this->ctx->sessionId());
            Audit::instance()->record('SESSIONS_REVOKED', 'user', $this->ctx->userId(), null, ['count' => $n]);

            return $n;
        }, static fn ($n) => $n === 0 ? 'There were no other sessions.' : "Signed out {$n} other session(s).", site_url('account/security'));
    }
}
