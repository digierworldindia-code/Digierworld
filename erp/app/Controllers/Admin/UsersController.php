<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Exceptions\BusinessRuleException;
use App\Services\Security\UserService;
use Config\Permissions;

/**
 * Owner-controlled user administration: create users, assign one or more
 * roles (optionally time-limited), per-user grant/deny overrides, record
 * allowlists, sessions, password reset, activation.
 */
class UsersController extends BaseController
{
    public function index()
    {
        $q      = trim((string) $this->request->getGet('q'));
        $status = (string) $this->request->getGet('status');
        $b      = $this->db->table('users u')
            ->select('u.id, u.active, u.last_active, p.full_name, p.phone, p.designation, p.last_seen_at, p.is_service_account, i.secret AS email')
            ->join('user_profiles p', 'p.user_id = u.id', 'left')
            ->join('auth_identities i', "i.user_id = u.id AND i.type = 'email_password'", 'left')
            ->where('u.deleted_at', null);
        if ($q !== '') {
            $b->groupStart()->like('p.full_name', $q)->orLike('i.secret', $q)->orLike('p.phone', $q)->groupEnd();
        }
        if ($status === 'active' || $status === 'inactive') {
            $b->where('u.active', $status === 'active' ? 1 : 0);
        }
        $users = $b->orderBy('p.full_name')->get()->getResultArray();
        $roles = [];
        foreach ($this->db->table('user_roles ur')->select('ur.user_id, r.name, r.is_super, ur.expires_at')->join('roles r', 'r.id = ur.role_id')->get()->getResultArray() as $r) {
            $roles[(int) $r['user_id']][] = $r;
        }

        return $this->render('admin/users/index', [
            'pageTitle' => 'Users', 'breadcrumbs' => [['Users']],
            'users' => $users, 'roles' => $roles, 'q' => $q, 'status' => $status,
        ]);
    }

    public function new()
    {
        return $this->render('admin/users/form', [
            'pageTitle'   => 'Add user',
            'breadcrumbs' => [['Users', 'admin/users'], ['Add user']],
            'roles'       => $this->db->table('roles')->where('is_active', 1)->orderBy('is_super', 'DESC')->orderBy('name')->get()->getResultArray(),
            'managers'    => $this->userOptions(),
            'canAssignSuper' => $this->authz->isSuper(),
        ]);
    }

    public function create()
    {
        $roleIds = array_map('intval', (array) $this->request->getPost('roles'));
        $roles   = $roleIds === [] ? [] : $this->db->table('roles')->whereIn('id', $roleIds)->get()->getResultArray();
        if ($roles === []) {
            return $this->backWithErrors(['roles' => 'Select at least one role.']);
        }
        $err = $this->guardRoleGrant($roles);
        if ($err !== null) {
            return $this->backWithErrors(['admin_password' => $err]);
        }
        $password  = (string) $this->request->getPost('password');
        $generated = false;
        if ($password === '') {
            $password  = UserService::randomPassword();
            $generated = true;
        }
        $expires = ymd((string) $this->request->getPost('role_expires_at'));

        try {
            $id = service('users')->create(
                (string) $this->request->getPost('email'),
                trim((string) $this->request->getPost('full_name')),
                $password,
                array_column($roles, 'code'),
                [
                    'phone'                => $this->post('phone'),
                    'designation'          => $this->post('designation'),
                    'manager_user_id'      => $this->post('manager_user_id') ? (int) $this->post('manager_user_id') : null,
                    'must_change_password' => 1,
                    'role_expires_at'      => $expires ? $expires . ' 23:59:59' : null,
                ]
            );
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        $msg = 'User created. They must set a new password at first sign-in.';
        if ($generated) {
            session()->setFlashdata('warning', 'Temporary password (shown once — share it securely): ' . $password);
        }

        return redirect()->to(site_url('admin/users/' . $id))->with('success', $msg);
    }

    public function show($id)
    {
        $id   = $this->idOr404($id);
        $user = $this->db->table('users u')
            ->select('u.id, u.active, u.created_at, p.*, i.secret AS email')
            ->join('user_profiles p', 'p.user_id = u.id', 'left')
            ->join('auth_identities i', "i.user_id = u.id AND i.type = 'email_password'", 'left')
            ->where('u.id', $id)->where('u.deleted_at', null)->get()->getRowArray();
        if ($user === null) {
            $this->notFound();
        }
        $summary   = $this->authz->effectiveSummary($id);
        $registry  = config(Permissions::class);
        $granted   = array_keys(array_filter($summary, static fn ($s) => $s['granted']));
        $flat      = $registry->flat();
        $dangerous = array_values(array_filter($granted, static fn ($k) => $flat[$k]['danger']));
        $sod       = [];
        foreach ($registry->sodWarnings as [$a, $b, $why]) {
            if (in_array($a, $granted, true) && in_array($b, $granted, true)) {
                $sod[] = $why;
            }
        }

        return $this->render('admin/users/show', [
            'pageTitle'   => $user['full_name'] ?: $user['email'],
            'breadcrumbs' => [['Users', 'admin/users'], [$user['full_name'] ?: $user['email']]],
            'user'        => $user,
            'allRoles'    => $this->db->table('roles')->where('is_active', 1)->orderBy('is_super', 'DESC')->orderBy('name')->get()->getResultArray(),
            'userRoles'   => array_column($this->db->table('user_roles')->where('user_id', $id)->get()->getResultArray(), null, 'role_id'),
            'summary'     => $summary,
            'modules'     => $registry->modules,
            'flat'        => $flat,
            'dangerous'   => $dangerous,
            'sod'         => $sod,
            'overrides'   => $this->db->table('user_permission_overrides o')->select('o.*, p.perm_key')->join('permissions p', 'p.id = o.permission_id')->where('o.user_id', $id)->get()->getResultArray(),
            'scopes'      => array_map('intval', array_column($this->db->table('user_record_scopes')->where(['user_id' => $id, 'scope_type' => 'hr.employee'])->get()->getResultArray(), 'record_id')),
            'employees'   => $this->db->table('employees')->select('id, employee_code, full_name')->orderBy('full_name')->get()->getResultArray(),
            'sessions'    => $this->db->table('user_sessions')->where('user_id', $id)->orderBy('last_activity_at', 'DESC')->limit(15)->get()->getResultArray(),
            'managers'    => $this->userOptions($id),
            'isSuperUser' => $this->authz->isSuper($id),
            'actorIsSuper'=> $this->authz->isSuper(),
            'lastChange'  => $this->db->table('audit_logs')->where('entity_type', 'user')->where('entity_id', $id)->whereIn('action', ['user.roles_changed', 'user.override_added', 'user.override_removed', 'user.scopes_changed', 'user.created'])->orderBy('id', 'DESC')->limit(1)->get()->getRowArray(),
        ]);
    }

    public function update($id)
    {
        $id = $this->idOr404($id);
        $this->assertUserExists($id);
        $before = $this->db->table('user_profiles')->where('user_id', $id)->get()->getRowArray();
        $mgr    = $this->post('manager_user_id') ? (int) $this->post('manager_user_id') : null;
        if ($mgr === $id) {
            return $this->backWithErrors(['manager_user_id' => 'A user cannot report to themselves.']);
        }
        $data = [
            'full_name'       => trim((string) $this->request->getPost('full_name')) ?: $before['full_name'],
            'phone'           => $this->post('phone'),
            'designation'     => $this->post('designation'),
            'manager_user_id' => $mgr,
            'updated_by'      => $this->userId,
        ];
        $this->db->table('user_profiles')->where('user_id', $id)->update($data);
        service('users')->touchPermissions($id); // team scope may change
        service('audit')->log('user.updated', 'user', $id, $before, $data, 'Profile updated');

        return redirect()->back()->with('success', 'User profile saved.');
    }

    public function updateRoles($id)
    {
        $id      = $this->idOr404($id);
        $this->assertUserExists($id);
        $roleIds = array_map('intval', (array) $this->request->getPost('roles'));
        $roles   = $roleIds === [] ? [] : $this->db->table('roles')->whereIn('id', $roleIds)->get()->getResultArray();
        $current = service('users')->roleIds($id);
        $added   = array_filter($roles, static fn ($r) => ! in_array((int) $r['id'], $current, true));
        $err     = $this->guardRoleGrant($added);
        if ($err !== null) {
            return $this->backWithErrors(['admin_password' => $err]);
        }
        $map = [];
        foreach ($roles as $r) {
            $exp = ymd((string) ($this->request->getPost('expires_' . $r['id']) ?? ''));
            $map[(int) $r['id']] = $exp ? $exp . ' 23:59:59' : null;
        }

        try {
            service('users')->syncRoles($id, $map);
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->back()->with('success', 'Roles updated. Changes apply on the user\'s next request.');
    }

    public function addOverride($id)
    {
        $id     = $this->idOr404($id);
        $this->assertUserExists($id);
        $key    = (string) $this->request->getPost('perm_key');
        $effect = (string) $this->request->getPost('effect');
        $reason = trim((string) $this->request->getPost('reason'));
        $flat   = config(Permissions::class)->flat();
        if (! isset($flat[$key]) || ! in_array($effect, ['grant', 'deny'], true)) {
            return $this->backWithErrors(['perm_key' => 'Choose a valid permission and effect.']);
        }
        if ($reason === '') {
            return $this->backWithErrors(['reason' => 'A reason is required for every override.']);
        }
        if ($effect === 'grant' && $flat[$key]['danger'] && ($e = $this->requirePassword()) !== null) {
            return $this->backWithErrors(['admin_password' => $e]);
        }
        $permId = (int) $this->db->table('permissions')->select('id')->where('perm_key', $key)->get()->getRowArray()['id'];
        $exp    = ymd((string) $this->request->getPost('expires_at'));
        $this->db->query(
            'INSERT INTO user_permission_overrides (user_id, permission_id, effect, reason, expires_at, created_by) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE effect = VALUES(effect), reason = VALUES(reason), expires_at = VALUES(expires_at), created_by = VALUES(created_by), created_at = NOW()',
            [$id, $permId, $effect, $reason, $exp ? $exp . ' 23:59:59' : null, $this->userId]
        );
        service('users')->touchPermissions($id);
        service('audit')->log('user.override_added', 'user', $id, null, ['perm' => $key, 'effect' => $effect, 'reason' => $reason, 'expires' => $exp], "Override {$effect} {$key}");

        return redirect()->back()->with('success', "Override saved: {$effect} {$key}.");
    }

    public function deleteOverride($id, $overrideId)
    {
        $id  = $this->idOr404($id);
        $row = $this->db->table('user_permission_overrides o')->select('o.*, p.perm_key')->join('permissions p', 'p.id = o.permission_id')
            ->where(['o.id' => $this->idOr404($overrideId), 'o.user_id' => $id])->get()->getRowArray();
        if ($row === null) {
            $this->notFound();
        }
        $this->db->table('user_permission_overrides')->where('id', $row['id'])->delete();
        service('users')->touchPermissions($id);
        service('audit')->log('user.override_removed', 'user', $id, ['perm' => $row['perm_key'], 'effect' => $row['effect']], null, 'Override removed');

        return redirect()->back()->with('success', 'Override removed.');
    }

    public function updateScopes($id)
    {
        $id  = $this->idOr404($id);
        $this->assertUserExists($id);
        $ids = array_values(array_unique(array_map('intval', (array) $this->request->getPost('employee_ids'))));
        $before = array_map('intval', array_column($this->db->table('user_record_scopes')->where(['user_id' => $id, 'scope_type' => 'hr.employee'])->get()->getResultArray(), 'record_id'));
        $this->db->transStart();
        $this->db->table('user_record_scopes')->where(['user_id' => $id, 'scope_type' => 'hr.employee'])->delete();
        foreach ($ids as $eid) {
            if ($eid > 0) {
                $this->db->table('user_record_scopes')->insert(['user_id' => $id, 'scope_type' => 'hr.employee', 'record_id' => $eid, 'created_by' => $this->userId]);
            }
        }
        $this->db->transComplete();
        service('users')->touchPermissions($id);
        service('audit')->log('user.scopes_changed', 'user', $id, ['employee_ids' => $before], ['employee_ids' => $ids], 'Employee allowlist changed');

        return redirect()->back()->with('success', $ids === [] ? 'Employee allowlist cleared (access follows role permissions).' : 'Employee allowlist saved (' . count($ids) . ' employees).');
    }

    public function resetPassword($id)
    {
        $id       = $this->idOr404($id);
        $this->assertUserExists($id);
        $password = (string) $this->request->getPost('password');
        $generated = $password === '';
        if ($generated) {
            $password = UserService::randomPassword();
        }

        try {
            service('users')->resetPassword($id, $password, true);
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }
        if ($generated) {
            session()->setFlashdata('warning', 'Temporary password (shown once): ' . $password);
        }

        return redirect()->back()->with('success', 'Password reset; all of the user\'s sessions were signed out and they must choose a new password.');
    }

    public function toggleActive($id)
    {
        $id = $this->idOr404($id);
        $this->assertUserExists($id);
        if ($id === $this->userId) {
            return redirect()->back()->with('error', 'You cannot deactivate your own account.');
        }
        $active = (int) $this->db->table('users')->select('active')->where('id', $id)->get()->getRowArray()['active'] === 1;

        try {
            service('users')->setActive($id, ! $active);
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->back()->with('success', $active ? 'User deactivated and signed out everywhere.' : 'User activated.');
    }

    public function revokeSessions($id)
    {
        $id = $this->idOr404($id);
        $this->assertUserExists($id);
        $n  = service('users')->revokeSessions($id, 'Revoked by administrator', $id === $this->userId);
        service('audit')->log('session.revoked_all', 'user', $id, null, null, "Revoked {$n} sessions");

        return redirect()->back()->with('success', "{$n} session(s) revoked. The user is signed out on their next request.");
    }

    // ---------------------------------------------------------------

    private function assertUserExists(int $id): void
    {
        if ($this->db->table('users')->where('id', $id)->where('deleted_at', null)->countAllResults() === 0) {
            $this->notFound();
        }
    }

    /** @return list<array{id: int, name: string}> */
    private function userOptions(?int $exclude = null): array
    {
        $b = $this->db->table('users u')->select('u.id, p.full_name')->join('user_profiles p', 'p.user_id = u.id')
            ->where('u.active', 1)->where('u.deleted_at', null)->where('p.is_service_account', 0);
        if ($exclude !== null) {
            $b->where('u.id !=', $exclude);
        }

        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['full_name']], $b->orderBy('p.full_name')->get()->getResultArray());
    }

    /**
     * Super roles can only be granted by a super admin; roles containing
     * dangerous permissions require the acting admin's password.
     *
     * @param list<array<string, mixed>> $roles
     */
    private function guardRoleGrant(array $roles): ?string
    {
        if ($roles === []) {
            return null;
        }
        $superRole = array_filter($roles, static fn ($r) => (int) $r['is_super'] === 1);
        if ($superRole !== [] && ! $this->authz->isSuper()) {
            return 'Only an Owner/Super Admin can grant the Super Admin role.';
        }
        $ids     = array_map(static fn ($r) => (int) $r['id'], $roles);
        $danger  = $superRole !== [] || $this->db->table('role_permissions rp')->join('permissions p', 'p.id = rp.permission_id')
            ->whereIn('rp.role_id', $ids)->where('p.is_dangerous', 1)->countAllResults() > 0;

        return $danger ? $this->requirePassword() : null;
    }

    /** Re-verify the acting admin's password for dangerous grants. */
    private function requirePassword(): ?string
    {
        if (! service('settingsStore')->get('security.dangerous_grant_requires_password', true)) {
            return null;
        }
        $pw = (string) $this->request->getPost('admin_password');
        if ($pw === '') {
            return 'This change grants sensitive permissions. Re-enter YOUR password to confirm.';
        }
        $email = $this->db->table('auth_identities')->select('secret')->where(['user_id' => $this->userId, 'type' => 'email_password'])->get()->getRowArray()['secret'] ?? '';
        if (! auth('session')->check(['email' => $email, 'password' => $pw])->isOK()) {
            service('audit')->log('security.reauth_failed', 'user', $this->userId, null, null, 'Password re-entry failed for privileged change');

            return 'Your password was incorrect.';
        }

        return null;
    }
}
