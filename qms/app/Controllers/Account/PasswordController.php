<?php

namespace App\Controllers\Account;

use App\Controllers\BaseController;

class PasswordController extends BaseController
{
    public function edit(): string
    {
        $user = $this->currentUser();

        return view('account/password', [
            'title'  => 'Change password',
            'forced' => (int) ($user['must_change_password'] ?? 0) === 1 || $this->expired($user),
            'rules'  => service('passwordPolicy')->describe(),
            'errors' => session()->getFlashdata('errors') ?? [],
        ]);
    }

    /**
     * @param array<string, mixed>|null $user
     */
    private function expired(?array $user): bool
    {
        $days = service('settings')->int('security.password_expiry_days', 0);
        if ($user === null || $days <= 0) {
            return false;
        }
        $changed = (string) ($user['password_changed_at'] ?? '');

        return $changed === '' || $changed < service('clock')->nowUtc()->modify("-{$days} days")->format('Y-m-d H:i:s');
    }

    public function update()
    {
        $user = $this->currentUser();

        return $this->perform(
            fn () => service('auth')->changePassword(
                $user['id'],
                (string) $this->request->getPost('current_password'),
                (string) $this->request->getPost('new_password'),
                (string) $this->request->getPost('confirm_password'),
            ),
            site_url('/'),
            'Your password has been changed.',
        );
    }
}
