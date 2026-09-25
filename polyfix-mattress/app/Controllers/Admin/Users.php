<?php

namespace App\Controllers\Admin;

use App\Libraries\Rbac;
use App\Services\UserService;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Platform accounts and the role matrix.
 *
 * Nobody can create or alter an account senior to their own, and nobody can
 * change their own roles — enforced in UserService, not here.
 */
class Users extends AdminController
{
    public function index(): string
    {
        $db     = db_connect();
        $role   = $this->oneOf('role', Rbac::ROLE_KEYS);
        $status = $this->oneOf('status', ['PENDING_ACTIVATION', 'ACTIVE', 'SUSPENDED', 'DISABLED']);
        $q      = $this->filter('q', 80);

        $builder = $db->table('users u')
            ->select("u.id, u.email, u.full_name, u.status, u.mfa_enabled, u.last_login_at, u.locked_until, u.must_change_password,
                      GROUP_CONCAT(r.key ORDER BY r.rank SEPARATOR ',') roles, d.business_name dealer", false)
            ->join('user_roles ur', 'ur.user_id = u.id', 'left')->join('roles r', 'r.id = ur.role_id', 'left')
            ->join('dealer_users du', 'du.user_id = u.id', 'left')->join('dealers d', 'd.id = du.dealer_id', 'left')
            ->where('u.deleted_at', null)->groupBy('u.id')->orderBy('u.full_name');
        if ($status) {
            $builder->where('u.status', $status);
        }
        if ($role) {
            $builder->where('EXISTS (SELECT 1 FROM user_roles x JOIN roles rr ON rr.id = x.role_id WHERE x.user_id = u.id AND rr.key = ' . $db->escape($role) . ')', null, false);
        }
        if ($q) {
            $term = $this->like($q);
            $builder->groupStart()->like('u.full_name', $term)->orLike('u.email', $term)->groupEnd();
        }

        return $this->render('admin/users/index', 'Users', 'admin/users', [
            'list' => $this->paginate($builder), 'f' => compact('role', 'status', 'q'),
        ]);
    }

    public function new(): string
    {
        return $this->render('admin/users/new', 'New user', 'admin/users', [
            'dealers' => db_connect()->table('dealers')->select('id, code, business_name, city')->where(['deleted_at' => null, 'status' => 'ACTIVE'])->orderBy('business_name')->get()->getResultArray(),
        ]);
    }

    public function create(): RedirectResponse
    {
        $in = $this->validated([
            'email'     => 'required|max_length[255]|valid_email',
            'full_name' => 'required|max_length[160]|safe_text',
            'phone'     => 'permit_empty|indian_mobile',
            'role'      => 'required|in_list[' . implode(',', Rbac::ROLE_KEYS) . ']',
        ]);
        if ($in instanceof RedirectResponse) {
            return $in;
        }
        $dealerId = $this->filter('dealer_id', 36) ?? (string) $this->request->getPost('dealer_id');

        return $this->act(function () use ($in, $dealerId) {
            $created = UserService::instance()->create([
                'email'     => $in['email'],
                'full_name' => $in['full_name'],
                'phone'     => $in['phone'] ?? null,
                'roles'     => [$in['role']],
                'dealer_id' => $in['role'] === 'DEALER' ? $dealerId : null,
            ], $this->ctx->roles());
            // Shown once, on the next page, and never stored in readable form.
            session()->setFlashdata('temporary_password', $created['temporary_password'] . '|' . $created['email']);

            return $created;
        }, 'Account created. The temporary password is shown below — hand it over in person or by phone, not by email.', site_url('admin/users'));
    }

    public function show(string $id): string
    {
        $db   = db_connect();
        $user = $db->table('users u')->select('u.id, u.email, u.full_name, u.phone, u.status, u.mfa_enabled, u.mfa_enrolled_at,
                    u.must_change_password, u.password_changed_at, u.failed_login_count, u.locked_until, u.last_login_at, u.last_login_ip, u.created_at, d.business_name dealer, d.id dealer_id')
            ->join('dealer_users du', 'du.user_id = u.id', 'left')->join('dealers d', 'd.id = du.dealer_id', 'left')
            ->where(['u.id' => $id, 'u.deleted_at' => null])->get()->getRowArray() ?? $this->notFound();

        $service = UserService::instance();

        return $this->render('admin/users/show', $user['full_name'], 'admin/users', [
            'u'        => $user,
            'roles'    => $service->rolesOf($id),
            'sessions' => $db->table('sessions')->select('id, ip, user_agent, created_at, last_seen_at, mfa_satisfied')
                ->where('user_id', $id)->where('revoked_at', null)->where('absolute_expiry >', utc_now())->orderBy('last_seen_at', 'DESC')->get()->getResultArray(),
            'recent'   => $db->table('audit_logs')->select('occurred_at, action, entity, entity_id')
                ->where('user_id', $id)->orderBy('occurred_at', 'DESC')->limit(15)->get()->getResultArray(),
            'canManage' => $service->rankOf($service->rolesOf($id)) >= $service->rankOf($this->ctx->roles()),
        ]);
    }

    public function update(string $id): RedirectResponse
    {
        $in = $this->validated([
            'full_name' => 'required|max_length[160]|safe_text',
            'phone'     => 'permit_empty|indian_mobile',
            'status'    => 'required|in_list[ACTIVE,SUSPENDED,DISABLED]',
        ]);
        if ($in instanceof RedirectResponse) {
            return $in;
        }

        return $this->act(fn () => UserService::instance()->update($id, $in, $this->ctx->roles()), 'Account updated.', site_url('admin/users/' . $id));
    }

    public function roles(string $id): RedirectResponse
    {
        $roles = array_values(array_filter((array) $this->request->getPost('roles'), static fn ($r) => in_array($r, Rbac::ROLE_KEYS, true)));

        return $this->act(
            fn () => UserService::instance()->assignRoles($id, $roles, $this->post('reason', 500), $this->ctx->roles()),
            'Roles changed. Their signed-in sessions have ended.',
            site_url('admin/users/' . $id),
        );
    }

    public function forceReset(string $id): RedirectResponse
    {
        return $this->act(function () use ($id) {
            $password = UserService::instance()->forcePasswordReset($id, $this->post('reason', 500), $this->ctx->roles());
            $email    = UserService::instance()->find($id)['email'];
            session()->setFlashdata('temporary_password', $password . '|' . $email);

            return $password;
        }, 'Password reset. The temporary password is shown below — hand it over in person or by phone.', site_url('admin/users/' . $id));
    }

    public function resetMfa(string $id): RedirectResponse
    {
        return $this->act(
            fn () => UserService::instance()->resetMfa($id, $this->post('reason', 500), $this->ctx->roles()),
            'Two-factor authentication cleared. They will enrol a new device at next sign-in.',
            site_url('admin/users/' . $id),
        );
    }

    /** What each role can do — generated from the same table the filters use. */
    public function roleMatrix(): string
    {
        $counts = [];
        foreach (db_connect()->table('user_roles ur')->select('r.key, COUNT(*) n')->join('roles r', 'r.id = ur.role_id')
            ->groupBy('r.key')->get()->getResultArray() as $row) {
            $counts[$row['key']] = (int) $row['n'];
        }
        $groups = [];
        foreach (Rbac::PERMISSIONS as $key => $meta) {
            $groups[$meta['group']][$key] = $meta['description'];
        }

        return $this->render('admin/users/roles', 'Roles & permissions', 'admin/roles', ['groups' => $groups, 'counts' => $counts]);
    }
}
