<?php

namespace App\Controllers\Admin;

use App\Libraries\Landing;
use App\Controllers\BaseController;
use Config\Permissions;

/**
 * Role & permission-matrix editor. Owners create custom roles without code;
 * names are labels only — enforcement uses the permission keys.
 */
class RolesController extends BaseController
{
    public function index()
    {
        $roles = $this->db->query(
            'SELECT r.*, (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) users,
                    (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) perms,
                    (SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = r.id AND p.is_dangerous = 1) danger
             FROM roles r ORDER BY r.is_super DESC, r.name'
        )->getResultArray();

        return $this->render('admin/roles/index', [
            'pageTitle' => 'Roles & permissions', 'breadcrumbs' => [['Users', 'admin/users'], ['Roles']],
            'roles' => $roles, 'total' => count(config(Permissions::class)->flat()),
        ]);
    }

    public function new()
    {
        return $this->form(null);
    }

    public function edit($id)
    {
        $role = $this->db->table('roles')->where('id', $this->idOr404($id))->get()->getRowArray() ?? $this->notFound();

        return $this->form($role);
    }

    private function form(?array $role)
    {
        $selected = [];
        if ($role !== null) {
            $selected = array_column($this->db->table('role_permissions rp')->select('p.perm_key')->join('permissions p', 'p.id = rp.permission_id')
                ->where('rp.role_id', $role['id'])->get()->getResultArray(), 'perm_key');
        }
        if ($role === null && ($from = $this->request->getGet('from'))) {
            $selected = array_column($this->db->table('role_permissions rp')->select('p.perm_key')->join('permissions p', 'p.id = rp.permission_id')
                ->where('rp.role_id', (int) $from)->get()->getResultArray(), 'perm_key');
        }

        return $this->render('admin/roles/form', [
            'pageTitle'   => $role ? 'Edit role: ' . $role['name'] : 'New role',
            'breadcrumbs' => [['Users', 'admin/users'], ['Roles', 'admin/roles'], [$role ? $role['name'] : 'New role']],
            'role'        => $role,
            'selected'    => array_flip($selected),
            'modules'     => config(Permissions::class)->modules,
            'userCount'   => $role ? $this->db->table('user_roles')->where('role_id', $role['id'])->countAllResults() : 0,
            'landings'    => Landing::options(),
        ]);
    }

    /** Posted landing page, if it is one of the offered options (null = automatic). */
    private function postedLanding(): ?string
    {
        $value = $this->request->getPost('landing_url');
        if ($value === null || $value === 'auto') {
            return null;
        }
        $value = trim((string) $value, '/');

        return array_key_exists($value, Landing::options()) ? $value : null;
    }

    public function create()
    {
        $name = trim((string) $this->request->getPost('name'));
        $code = strtolower(trim(preg_replace('/[^a-z0-9_]+/i', '_', (string) ($this->request->getPost('code') ?: $name)), '_'));
        if ($name === '' || $code === '') {
            return $this->backWithErrors(['name' => 'Role name is required.']);
        }
        if ($this->db->table('roles')->where('code', $code)->countAllResults() > 0) {
            return $this->backWithErrors(['code' => 'A role with this code already exists.']);
        }
        $perms = $this->postedPerms();
        if (($err = $this->dangerCheck($perms, [])) !== null) {
            return $this->backWithErrors(['admin_password' => $err]);
        }
        $this->db->transBegin();
        try {
            $this->db->table('roles')->insert([
                'code' => $code, 'name' => $name, 'description' => $this->post('description'), 'landing_url' => $this->postedLanding(), 'is_super' => 0, 'is_system' => 0,
                'is_active' => 1, 'created_by' => $this->userId,
            ]);
            $id = (int) $this->db->insertID();
            $this->syncPerms($id, $perms);
            service('audit')->log('role.created', 'role', $id, null, ['code' => $code, 'name' => $name, 'perms' => $perms], 'Role created: ' . $name);
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        return redirect()->to(site_url('admin/roles/' . $id))->with('success', 'Role created with ' . count($perms) . ' permissions.');
    }

    public function update($id)
    {
        $role = $this->db->table('roles')->where('id', $this->idOr404($id))->get()->getRowArray() ?? $this->notFound();
        $name = trim((string) $this->request->getPost('name')) ?: $role['name'];
        $active = (int) ($this->request->getPost('is_active') ? 1 : 0);
        if ((int) $role['is_super'] === 1) {
            if ($active === 0) {
                return redirect()->back()->with('error', 'The Super Admin role cannot be deactivated.');
            }
            $this->db->table('roles')->where('id', $role['id'])->update(['name' => $name, 'description' => $this->post('description'), 'updated_by' => $this->userId]);
            service('audit')->log('role.updated', 'role', (int) $role['id'], ['name' => $role['name']], ['name' => $name], 'Super role label updated');

            return redirect()->back()->with('success', 'Role details saved (Super Admin always holds every permission).');
        }
        $before = array_column($this->db->table('role_permissions rp')->select('p.perm_key')->join('permissions p', 'p.id = rp.permission_id')
            ->where('rp.role_id', $role['id'])->get()->getResultArray(), 'perm_key');
        $perms = $this->postedPerms();
        if (($err = $this->dangerCheck($perms, $before)) !== null) {
            return $this->backWithErrors(['admin_password' => $err]);
        }
        $this->db->transBegin();
        try {
            $this->db->table('roles')->where('id', $role['id'])->update([
                'name' => $name, 'description' => $this->post('description'), 'is_active' => $active, 'updated_by' => $this->userId,
            ] + ($this->request->getPost('landing_url') !== null ? ['landing_url' => $this->postedLanding()] : []));
            $this->syncPerms((int) $role['id'], $perms);
            service('audit')->log('role.permissions_changed', 'role', (int) $role['id'], ['perms' => $before], ['perms' => $perms], sprintf(
                'Role %s: +%d / -%d permissions',
                $name,
                count(array_diff($perms, $before)),
                count(array_diff($before, $perms))
            ));
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
        foreach (array_column($this->db->table('user_roles')->select('user_id')->where('role_id', $role['id'])->get()->getResultArray(), 'user_id') as $uid) {
            service('users')->touchPermissions((int) $uid);
        }

        return redirect()->back()->with('success', 'Role saved. Changes apply to its users on their next request.');
    }

    public function clone($id)
    {
        $role = $this->db->table('roles')->where('id', $this->idOr404($id))->get()->getRowArray() ?? $this->notFound();

        return redirect()->to(site_url('admin/roles/new?from=' . $role['id']))->with('info', 'Permissions copied from "' . $role['name'] . '". Give the new role a name and save.');
    }

    public function delete($id)
    {
        $role = $this->db->table('roles')->where('id', $this->idOr404($id))->get()->getRowArray() ?? $this->notFound();
        if ((int) $role['is_super'] === 1) {
            return redirect()->back()->with('error', 'The Super Admin role cannot be deleted.');
        }
        if ($this->db->table('user_roles')->where('role_id', $role['id'])->countAllResults() > 0) {
            return redirect()->back()->with('error', 'Remove this role from all users before deleting it (or deactivate it instead).');
        }
        $this->db->transBegin();
        try {
            $this->db->table('signature_policies')->where('role_id', $role['id'])->delete();
            $this->db->table('role_permissions')->where('role_id', $role['id'])->delete();
            $this->db->table('roles')->where('id', $role['id'])->delete();
            service('audit')->log('role.deleted', 'role', (int) $role['id'], ['code' => $role['code'], 'name' => $role['name']], null, 'Role deleted');
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        return redirect()->to(site_url('admin/roles'))->with('success', 'Role deleted.');
    }

    public function matrix()
    {
        $roles = $this->db->table('roles')->orderBy('is_super', 'DESC')->orderBy('name')->get()->getResultArray();
        $grant = [];
        foreach ($this->db->table('role_permissions rp')->select('rp.role_id, p.perm_key')->join('permissions p', 'p.id = rp.permission_id')->get()->getResultArray() as $g) {
            $grant[$g['perm_key']][(int) $g['role_id']] = true;
        }

        return $this->render('admin/roles/matrix', [
            'pageTitle' => 'Permission matrix', 'breadcrumbs' => [['Roles', 'admin/roles'], ['Matrix']],
            'roles' => $roles, 'grant' => $grant, 'modules' => config(Permissions::class)->modules,
        ]);
    }

    /** @return list<string> */
    private function postedPerms(): array
    {
        $flat = config(Permissions::class)->flat();

        return array_values(array_filter(array_unique((array) $this->request->getPost('perms')), static fn ($k) => is_string($k) && isset($flat[$k])));
    }

    /** @param list<string> $perms @param list<string> $before */
    private function dangerCheck(array $perms, array $before): ?string
    {
        $flat  = config(Permissions::class)->flat();
        $added = array_filter(array_diff($perms, $before), static fn ($k) => $flat[$k]['danger']);
        if ($added === [] || ! service('settingsStore')->get('security.dangerous_grant_requires_password', true)) {
            return null;
        }
        $pw = (string) $this->request->getPost('admin_password');
        if ($pw === '') {
            return 'You are adding ' . count($added) . ' sensitive permission(s). Re-enter your password to confirm.';
        }
        $email = $this->db->table('auth_identities')->select('secret')->where(['user_id' => $this->userId, 'type' => 'email_password'])->get()->getRowArray()['secret'] ?? '';

        return auth('session')->check(['email' => $email, 'password' => $pw])->isOK() ? null : 'Your password was incorrect.';
    }

    /** @param list<string> $perms */
    private function syncPerms(int $roleId, array $perms): void
    {
        $this->db->table('role_permissions')->where('role_id', $roleId)->delete();
        if ($perms === []) {
            return;
        }
        $ids = $this->db->table('permissions')->select('id')->whereIn('perm_key', $perms)->get()->getResultArray();
        foreach ($ids as $p) {
            $this->db->table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $p['id'], 'granted_by' => $this->userId]);
        }
    }
}
