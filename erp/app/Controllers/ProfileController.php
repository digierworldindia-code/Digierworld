<?php

namespace App\Controllers;

use App\Exceptions\BusinessRuleException;

/** The signed-in user's own profile, password and sessions. */
class ProfileController extends BaseController
{
    public function index()
    {
        $uid      = (int) $this->userId;
        $profile  = $this->db->table('user_profiles')->where('user_id', $uid)->get()->getRowArray() ?? [];
        $email    = $this->db->table('auth_identities')->select('secret')->where(['user_id' => $uid, 'type' => 'email_password'])->get()->getRowArray()['secret'] ?? '';
        $roles    = $this->db->table('user_roles ur')->select('r.name, ur.expires_at')->join('roles r', 'r.id = ur.role_id')->where('ur.user_id', $uid)->get()->getResultArray();
        $sessions = $this->db->table('user_sessions')->where('user_id', $uid)->orderBy('last_activity_at', 'DESC')->limit(20)->get()->getResultArray();
        $logins   = $this->db->table('auth_logins')->where('user_id', $uid)->orderBy('date', 'DESC')->limit(10)->get()->getResultArray();

        return $this->render('profile/index', [
            'pageTitle'   => 'My profile & sessions',
            'breadcrumbs' => [['My profile']],
            'profile'     => $profile,
            'email'       => $email,
            'roles'       => $roles,
            'sessions'    => $sessions,
            'logins'      => $logins,
            'currentSid'  => session_id(),
            'perms'       => $this->authz->permissionsFor($uid),
        ]);
    }

    /** Saves the Light / Dark / System choice from the top bar (JSON). */
    public function theme()
    {
        $body  = $this->request->getJSON(true) ?? [];
        $theme = (string) ($body['theme'] ?? $this->request->getPost('theme'));
        if (! in_array($theme, ['light', 'dark', 'system'], true)) {
            return $this->json(['ok' => false, 'message' => 'Choose light, dark or system.'], 422);
        }
        $this->db->query("UPDATE user_profiles SET preferences = JSON_SET(COALESCE(preferences, '{}'), '$.theme', ?) WHERE user_id = ?", [$theme, (int) $this->userId]);

        return $this->json(['ok' => true, 'theme' => $theme]);
    }

    public function password()
    {
        return $this->render('profile/password', ['pageTitle' => 'Change password', 'breadcrumbs' => [['My profile', 'profile'], ['Change password']]]);
    }

    public function updatePassword()
    {
        $uid     = (int) $this->userId;
        $current = (string) $this->request->getPost('current_password');
        $new     = (string) $this->request->getPost('password');
        $confirm = (string) $this->request->getPost('password_confirm');

        $email = $this->db->table('auth_identities')->select('secret')->where(['user_id' => $uid, 'type' => 'email_password'])->get()->getRowArray()['secret'] ?? '';
        $check = auth('session')->check(['email' => $email, 'password' => $current]);
        if (! $check->isOK()) {
            return $this->backWithErrors(['current_password' => 'Current password is incorrect.']);
        }
        if ($new !== $confirm) {
            return $this->backWithErrors(['password_confirm' => 'New passwords do not match.']);
        }
        if ($new === $current) {
            return $this->backWithErrors(['password' => 'Choose a password different from the current one.']);
        }

        try {
            service('users')->resetPassword($uid, $new, false);
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to(site_url('/'))->with('success', 'Password changed. Other sessions were signed out.');
    }

    public function revokeSession($id)
    {
        $id  = $this->idOr404($id);
        $row = $this->db->table('user_sessions')->where(['id' => $id, 'user_id' => $this->userId])->get()->getRowArray();
        if ($row === null) {
            $this->notFound();
        }
        $this->db->table('user_sessions')->where('id', $id)->update([
            'revoked_at' => date('Y-m-d H:i:s'), 'revoked_by' => $this->userId, 'revoke_reason' => 'Revoked by user',
        ]);
        service('audit')->log('session.revoked', 'user_session', $id, null, null, 'User revoked own session');

        return redirect()->back()->with('success', 'Session signed out.');
    }
}
