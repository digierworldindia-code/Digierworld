<?php

namespace Tests\Feature;

use Tests\Support\DgrTestCase;

/**
 * Spec §16 / §25: the owner builds roles and users without code; every
 * permission is enforced server-side (UI, direct URLs, JSON, files);
 * overrides, expiry, dangerous-grant re-authentication and the
 * last-Super-Admin guard work.
 */
final class RbacAdminTest extends DgrTestCase
{
    private const OWNER_PW = 'Teal-Granite-Ledger-77!';

    private function owner(): \CodeIgniter\Shield\Entities\User
    {
        $email = 'rbac.owner.' . bin2hex(random_bytes(3)) . '@example.test';
        $id    = service('users')->create($email, 'Rbac Principal', self::OWNER_PW, ['super_admin']);

        return auth()->getProvider()->findById($id);
    }

    public function testOwnerCreatesRoleAndUserAndEveryLayerEnforcesIt(): void
    {
        $owner = $this->owner();
        $this->loginAs($owner);

        // a custom role, built from the permission registry in the UI
        $res = $this->postForm('admin/roles', ['name' => 'Invoice Clerk ' . bin2hex(random_bytes(2)), 'perms' => ['dashboard.view', 'billing.invoice.view_own', 'billing.invoice.create_draft', 'billing.invoice.edit_draft']]);
        $res->assertRedirect();
        $roleId = (int) db_connect()->table('roles')->like('name', 'Invoice Clerk', 'after')->orderBy('id', 'DESC')->get()->getRow()->id;
        $this->assertSame(4, db_connect()->table('role_permissions')->where('role_id', $roleId)->countAllResults());

        // a user holding only that role
        $email = 'clerk.' . bin2hex(random_bytes(3)) . '@example.test';
        $this->postForm('admin/users', ['email' => $email, 'full_name' => 'Clerk Person', 'password' => 'Copper-Kettle-Window-42', 'roles' => [(string) $roleId]])->assertRedirect();
        $uid = (int) db_connect()->table('auth_identities')->where('secret', $email)->get()->getRow()->user_id;
        $this->get('admin/users/' . $uid)->assertSee('billing.invoice.create_draft');

        $clerk = auth()->getProvider()->findById($uid);
        db_connect()->table('user_profiles')->where('user_id', $uid)->update(['must_change_password' => 0]);
        $this->loginAs($clerk);
        $this->assertSame(200, $this->statusOf(fn () => $this->get('billing/documents/new')));
        foreach (['finance/receipts', 'finance/accounts', 'reports', 'reports/gst', 'hr/payroll', 'hr/employees', 'crm/leads', 'contacts/export', 'admin/users', 'admin/roles', 'settings/banks', 'admin/ai', 'admin/backups'] as $uri) {
            $this->assertSame(403, $this->statusOf(fn () => $this->get($uri)), $uri);
        }
        // no finance or payroll totals through the dashboard JSON
        $charts = json_decode((string) $this->get('dashboard/data')->response()->getBody(), true)['charts'] ?? [];
        $this->assertSame([], array_intersect(array_keys($charts), ['billed_vs_collected', 'gst_split', 'payment_modes', 'expenses', 'aging', 'top_services', 'yoy']));
        $this->get('/')->assertDontSee('Payroll (gross)');

        // per-user deny override wins over the role
        $this->loginAs($owner);
        $this->postForm('admin/users/' . $uid . '/overrides', ['perm_key' => 'billing.invoice.create_draft', 'effect' => 'deny', 'reason' => 'Under training'])->assertRedirect();
        $this->loginAs($clerk);
        $this->assertSame(403, $this->statusOf(fn () => $this->get('billing/documents/new')));
    }

    public function testDangerousGrantNeedsPasswordAndExpiredRoleGrantsNothing(): void
    {
        $owner = $this->owner();
        $user  = $this->makeUser('employee', 'Temp Accountant');
        db_connect()->table('user_profiles')->where('user_id', $user->id)->update(['must_change_password' => 0]);
        $acct  = (int) db_connect()->table('roles')->where('code', 'accountant')->get()->getRow()->id;
        $emp   = (int) db_connect()->table('roles')->where('code', 'employee')->get()->getRow()->id;
        $this->loginAs($owner);

        // accountant role carries dangerous permissions → password re-entry required
        $this->postForm('admin/users/' . $user->id . '/roles', ['roles' => [(string) $emp, (string) $acct]])->assertRedirect();
        $this->assertNotContains($acct, service('users')->roleIds((int) $user->id));
        $this->postForm('admin/users/' . $user->id . '/roles', ['roles' => [(string) $emp, (string) $acct], 'admin_password' => 'wrong-password']);
        $this->assertNotContains($acct, service('users')->roleIds((int) $user->id));
        $this->postForm('admin/users/' . $user->id . '/roles', ['roles' => [(string) $emp, (string) $acct], 'admin_password' => self::OWNER_PW, 'expires_' . $acct => date('Y-m-d', strtotime('+1 day'))]);
        $this->assertContains($acct, service('users')->roleIds((int) $user->id));
        $this->assertNotNull(db_connect()->table('audit_logs')->where('action', 'security.reauth_failed')->where('user_id', $owner->id)->get()->getRow());

        $this->loginAs($user);
        $this->assertSame(200, $this->statusOf(fn () => $this->get('finance/receipts')));

        // the temporary grant lapses on its own
        db_connect()->table('user_roles')->where(['user_id' => $user->id, 'role_id' => $acct])->update(['expires_at' => date('Y-m-d H:i:s', strtotime('-1 minute'))]);
        service('authz')->forget();
        $this->loginAs($user);
        $this->assertSame(403, $this->statusOf(fn () => $this->get('finance/receipts')));
    }

    public function testWeakPasswordIsRefusedWithAReadableReason(): void
    {
        try {
            service('users')->assertPasswordStrength('kavita7', 'kavita.rao@example.test', 'Kavita Rao');
            $this->fail('A short password containing the name must be refused');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertSame('Password must be at least ' . config('Auth')->minimumPasswordLength . ' characters long, not contain your name.', $e->getMessage());
        }

        $this->loginAs($this->owner());
        $email = 'weak.' . bin2hex(random_bytes(3)) . '@example.test';
        $employee = (string) db_connect()->table('roles')->where('code', 'employee')->get()->getRow()->id;
        $this->postForm('admin/users', ['email' => $email, 'full_name' => 'Weak Password', 'password' => 'Weakpassword', 'roles' => [$employee]])->assertRedirect();
        $this->assertSame(0, db_connect()->table('auth_identities')->where('secret', $email)->countAllResults(), 'no user is created');
        $this->assertStringContainsString('Password must include both letters and numbers, not contain your name.', json_encode(session('errors') ?? session('error') ?? []));
    }

    public function testLastSuperAdminCannotBeRemovedOrDeactivated(): void
    {
        $owner = $this->owner();
        $db    = db_connect();
        $super = (int) $db->table('roles')->where('code', 'super_admin')->get()->getRow()->id;
        $others = array_map('intval', array_column($db->table('user_roles')->select('user_id')->where('role_id', $super)->where('user_id !=', $owner->id)->get()->getResultArray(), 'user_id'));
        if ($others !== []) {
            $db->table('users')->whereIn('id', $others)->update(['active' => 0]);
        }
        try {
            $this->assertSame(1, service('users')->activeSuperAdminCount());
            try {
                service('users')->syncRoles((int) $owner->id, []);
                $this->fail('Removing the last Super Admin role must be refused');
            } catch (\App\Exceptions\BusinessRuleException) {
                $this->assertContains($super, service('users')->roleIds((int) $owner->id));
            }
            try {
                service('users')->setActive((int) $owner->id, false);
                $this->fail('Deactivating the last Super Admin must be refused');
            } catch (\App\Exceptions\BusinessRuleException) {
                $this->assertSame(1, (int) $db->table('users')->where('id', $owner->id)->get()->getRow()->active);
            }
        } finally {
            if ($others !== []) {
                $db->table('users')->whereIn('id', $others)->update(['active' => 1]);
            }
        }
    }
}
