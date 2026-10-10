<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use CodeIgniter\Shield\Models\UserModel;

class Users extends AdminController
{
    public function index(): string
    {
        $b = db_connect()->table('users u')->select("u.id, u.active, u.last_active, u.created_at, ANY_VALUE(ai.secret) AS email, GROUP_CONCAT(DISTINCT g.group) AS groups_list, ANY_VALUE(c.legal_name) AS legal_name, ANY_VALUE(c.id) AS company_id, ANY_VALUE(c.company_type) AS company_type, ANY_VALUE(cm.member_role) AS member_role", false)
            ->join('auth_identities ai', "ai.user_id = u.id AND ai.type = 'email_password'", 'left')
            ->join('auth_groups_users g', 'g.user_id = u.id', 'left')
            ->join('company_members cm', 'cm.user_id = u.id', 'left')->join('companies c', 'c.id = cm.company_id', 'left')
            ->where('u.deleted_at', null)->groupBy('u.id');
        if ($q = trim((string) $this->request->getGet('q'))) {
            $b->groupStart()->like('ai.secret', $q)->orLike('c.legal_name', $q)->groupEnd();
        }
        if ($g = $this->request->getGet('group')) {
            $b->having('FIND_IN_SET(' . db_connect()->escape($g) . ', groups_list) >', 0);
        }
        $page  = max(1, (int) $this->request->getGet('page'));
        $total = (clone $b)->countAllResults(false);
        $rows  = $b->orderBy('u.id', 'DESC')->limit(30, ($page - 1) * 30)->get()->getResultArray();

        return $this->page('users', ['title' => 'Users', 'rows' => $rows, 'page' => $page, 'pages' => (int) ceil(max(1, $total) / 30), 'groups' => config('AuthGroups')->groups]);
    }

    public function show(int $id): string
    {
        $user = model(UserModel::class)->find($id);
        if (! $user) {
            $this->notFound();
        }
        $db = db_connect();

        return $this->page('user', [
            'title' => $user->email, 'user' => $user, 'groups' => $user->getGroups(),
            'membership' => $db->table('company_members cm')->select('cm.*, c.legal_name, c.company_type')->join('companies c', 'c.id = cm.company_id')->where('cm.user_id', $id)->get()->getResultArray(),
            'logins' => $db->table('auth_logins')->where('user_id', $id)->orderBy('id', 'DESC')->limit(15)->get()->getResultArray(),
            'audit' => $db->table('audit_logs')->where('user_id', $id)->orderBy('id', 'DESC')->limit(20)->get()->getResultArray(),
        ]);
    }

    public function toggle(int $id)
    {
        $users = model(UserModel::class);
        $user  = $users->find($id);
        if (! $user || (int) $user->id === $this->userId()) {
            return redirect()->back()->with('error', 'You cannot change this account.');
        }
        if ($user->inGroup('superadmin') && ! auth()->user()->inGroup('superadmin')) {
            return redirect()->back()->with('error', 'Only a super admin can change another super admin.');
        }
        $user->active ? $user->deactivate() : $user->activate();
        service('audit')->log($user->active ? 'user.activated' : 'user.deactivated', ['entity_type' => 'user', 'entity_id' => $id, 'severity' => 'warning']);

        return redirect()->back()->with('success', 'User ' . ($user->active ? 'activated' : 'deactivated') . '.');
    }

    /**
     * Account recovery without email: issues a one-time random password that
     * the admin passes to the user through a verified channel. The password is
     * shown once, never stored in plain text, and the action is audited.
     */
    public function temporaryPassword(int $id)
    {
        $users = model(UserModel::class);
        $user  = $users->find($id);
        if (! $user || (int) $user->id === $this->userId()) {
            return redirect()->back()->with('error', 'You cannot reset this account here. Use Account settings for your own password.');
        }
        if ($user->inGroup('superadmin') && ! auth()->user()->inGroup('superadmin')) {
            return redirect()->back()->with('error', 'Only a super admin can reset another super admin.');
        }
        $temp = 'Tmp-' . bin2hex(random_bytes(6)) . '!';
        $user->fill(['password' => $temp]);
        $users->save($user);
        service('audit')->log('user.temporary_password_issued', ['entity_type' => 'user', 'entity_id' => $id, 'severity' => 'security', 'description' => 'Temporary password issued by an administrator']);

        return redirect()->back()->with('temp_password', $temp)->with('success', 'Temporary password issued. Share it through a verified channel and ask the user to change it under Account settings.');
    }
}
