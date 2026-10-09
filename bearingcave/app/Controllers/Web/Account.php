<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;

/**
 * Personal account: password change and API access tokens.
 */
class Account extends BaseController
{
    public function index(): string
    {
        $user = auth()->user();

        return view('account/settings', [
            'title' => 'Account settings', 'area' => $user->can('admin.access') ? 'admin' : (service('companyContext')->company()['company_type'] ?? 'buyer'),
            'user' => $user, 'tokens' => $user->accessTokens(), 'newToken' => session()->getFlashdata('new_token'),
            'logins' => db_connect()->table('auth_logins')->where('user_id', $user->id)->orderBy('id', 'DESC')->limit(10)->get()->getResultArray(),
        ]);
    }

    public function password()
    {
        $magic = (bool) session('magicLoginPasswordReset');
        $rules = ['password' => 'required|strong_password[]', 'password_confirm' => 'required|matches[password]'];
        if (! $magic) {
            $rules['current_password'] = 'required';
        }
        if ($r = $this->invalid($rules)) {
            return $r;
        }
        $user = auth()->user();
        if (! $magic) {
            $check = auth()->check(['email' => $user->email, 'password' => (string) $this->request->getPost('current_password')]);
            if (! $check->isOK()) {
                return redirect()->back()->with('error', 'Your current password is incorrect.');
            }
        }
        $user->fill(['password' => $this->request->getPost('password')]);
        auth()->getProvider()->save($user);
        session()->remove('magicLoginPasswordReset');
        service('audit')->log('account.password_changed', ['entity_type' => 'user', 'entity_id' => (int) $user->id]);

        return redirect()->to(site_url('account'))->with('success', 'Password updated.');
    }

    public function createToken()
    {
        if ($r = $this->invalid(['name' => 'required|max_length[60]'])) {
            return $r;
        }
        $token = auth()->user()->generateAccessToken((string) $this->request->getPost('name'));
        service('audit')->log('api.token_created', ['entity_type' => 'user', 'entity_id' => $this->userId(), 'description' => (string) $this->request->getPost('name')]);

        return redirect()->to(site_url('account'))->with('new_token', $token->raw_token)->with('success', 'API token created. Copy it now — it will not be shown again.');
    }

    public function revokeToken(int $id)
    {
        $user = auth()->user();
        foreach ($user->accessTokens() as $t) {
            if ((int) $t->id === $id) {
                $user->revokeAccessTokenBySecret($t->secret);
                service('audit')->log('api.token_revoked', ['entity_type' => 'user', 'entity_id' => (int) $user->id]);

                return redirect()->back()->with('success', 'Token revoked.');
            }
        }
        $this->notFound();
    }
}
