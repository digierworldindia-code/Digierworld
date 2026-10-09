<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use CodeIgniter\Shield\Entities\User;
use Config\AuthGroups;

class Staff extends AdminController
{
    public function index(): string
    {
        $cfg  = config(AuthGroups::class);
        $rows = db_connect()->table('auth_groups_users g')->select('u.id, u.active, u.last_active, ANY_VALUE(ai.secret) AS email, GROUP_CONCAT(g.group) AS groups_list', false)
            ->join('users u', 'u.id = g.user_id')->join('auth_identities ai', "ai.user_id = u.id AND ai.type = 'email_password'", 'left')
            ->whereIn('g.group', $cfg->staffGroups)->groupBy('u.id')->orderBy('email')->get()->getResultArray();

        return $this->page('staff', ['title' => 'Staff & roles', 'rows' => $rows, 'groups' => array_intersect_key($cfg->groups, array_flip($cfg->staffGroups)), 'matrix' => $cfg->matrix, 'permissions' => $cfg->permissions]);
    }

    public function store()
    {
        $cfg = config(AuthGroups::class);
        if ($r = $this->invalid(['email' => 'required|valid_email|is_unique[auth_identities.secret]', 'password' => 'required|strong_password[]', 'groups' => 'required'])) {
            return $r;
        }
        $groups = array_values(array_intersect((array) $this->request->getPost('groups'), $cfg->staffGroups));
        if (in_array('superadmin', $groups, true) && ! auth()->user()->inGroup('superadmin')) {
            return redirect()->back()->withInput()->with('error', 'Only a super admin can create super admins.');
        }
        $users = auth()->getProvider();
        $user  = new User(['username' => null, 'email' => strtolower((string) $this->request->getPost('email')), 'password' => (string) $this->request->getPost('password')]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        foreach ($groups as $g) {
            $user->addGroup($g);
        }
        service('audit')->log('staff.created', ['entity_type' => 'user', 'entity_id' => (int) $user->id, 'description' => implode(',', $groups), 'severity' => 'warning']);

        return redirect()->back()->with('success', 'Staff account created. Share the temporary password securely.');
    }

    public function groups(int $id)
    {
        $cfg  = config(AuthGroups::class);
        $user = auth()->getProvider()->findById($id);
        if (! $user || (int) $user->id === $this->userId()) {
            return redirect()->back()->with('error', 'You cannot change your own roles.');
        }
        $groups = array_values(array_intersect((array) $this->request->getPost('groups'), $cfg->staffGroups));
        if ((in_array('superadmin', $groups, true) || $user->inGroup('superadmin')) && ! auth()->user()->inGroup('superadmin')) {
            return redirect()->back()->with('error', 'Only a super admin can grant or remove the super admin role.');
        }
        $keep = array_values(array_diff($user->getGroups(), $cfg->staffGroups)); // keep buyer/supplier groups
        $user->syncGroups(...array_merge($keep, $groups));
        service('audit')->log('staff.roles_changed', ['entity_type' => 'user', 'entity_id' => $id, 'description' => implode(',', $groups), 'severity' => 'warning']);

        return redirect()->back()->with('success', 'Roles updated.');
    }
}
