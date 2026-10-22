<?php

namespace Tests\Feature;

use Tests\Support\DgrTestCase;

/**
 * Sign-in through the real form: each role lands on its own dashboard, the
 * page asked for before signing in wins, a landing page the role may not
 * open is never used, and a sign-in whose session cookie never comes back
 * is explained instead of silently showing the sign-in page again.
 */
final class LoginLandingTest extends DgrTestCase
{
    private const PASSWORD = 'Lantern-Copper-Quay-58';

    private function user(string $role): string
    {
        $email = $role . '.' . bin2hex(random_bytes(3)) . '@landing.test';
        service('users')->create($email, 'Landing ' . ucwords(str_replace('_', ' ', $role)), self::PASSWORD, [$role]);

        return $email;
    }

    private function signIn(string $email)
    {
        if (auth('session')->loggedIn()) {
            auth('session')->logout();
        }
        service('authz')->forget();

        return $this->postForm('login', ['email' => $email, 'password' => self::PASSWORD]);
    }

    public function testEachRoleLandsOnItsDashboardAndCanOpenIt(): void
    {
        $expected = [
            'super_admin'      => '/',
            'accountant'       => '/',
            'crm_manager'      => '/',
            'billing_operator' => 'dashboard/billing',
            'telecaller'       => 'crm/today',
            'hr_manager'       => 'dashboard/hr',
            'payroll_manager'  => 'dashboard/hr',
            'employee'         => 'me',
        ];
        foreach ($expected as $role => $path) {
            $res = $this->signIn($this->user($role));
            $res->assertRedirectTo(site_url($path));
            service('authz')->forget();
            $this->assertSame(200, $this->statusOf(fn () => $this->get($path)), "{$role} can open {$path}");
        }
    }

    public function testThePageAskedForBeforeSignInWinsAndForbiddenLandingsAreNeverUsed(): void
    {
        // (the browser flow that records this page is covered in SignInFlowTest and on a real server)
        $email = $this->user('accountant');
        if (auth('session')->loggedIn()) {
            auth('session')->logout();
        }
        // feature tests start each request with an empty session unless one is given
        $token = csrf_hash(); // the CSRF token lives in the session (the security service keeps the one it already holds)
        $this->withSession(['csrf_test_name' => $token, 'beforeLoginUrl' => site_url('finance/receipts'), '__ci_vars' => ['beforeLoginUrl' => time() + 300]])
            ->post('login', ['email' => $email, 'password' => self::PASSWORD, 'csrf_test_name' => $token])->assertRedirectTo(site_url('finance/receipts'));
        $this->withSession([]);

        // a telecaller role pointed at the billing dashboard (no billing permissions) falls back to its dashboard
        $db = db_connect();
        $db->table('roles')->where('code', 'telecaller')->update(['landing_url' => 'dashboard/billing']);
        try {
            $this->signIn($this->user('telecaller'))->assertRedirectTo(site_url('/'));
        } finally {
            $db->table('roles')->where('code', 'telecaller')->update(['landing_url' => 'crm/today']);
        }
    }

    public function testRoleEditorSavesOnlyOfferedLandingPagesAndAreaDashboardsAreGated(): void
    {
        $owner = $this->makeUser('super_admin', 'Landing Owner');
        $this->loginAs($owner);
        $db     = db_connect();
        $roleId = (int) $db->table('roles')->where('code', 'auditor')->get()->getRow()->id;
        $perms  = array_column($db->table('role_permissions rp')->select('p.perm_key')->join('permissions p', 'p.id = rp.permission_id')->where('rp.role_id', $roleId)->get()->getResultArray(), 'perm_key');
        $post   = static fn (string $landing) => ['name' => 'Auditor', 'is_active' => '1', 'landing_url' => $landing, 'perms' => $perms];

        $this->postForm('admin/roles/' . $roleId, $post('reports'));
        $this->assertSame('reports', $db->table('roles')->where('id', $roleId)->get()->getRow()->landing_url);
        $this->postForm('admin/roles/' . $roleId, $post('../../etc/passwd'));
        $this->assertNull($db->table('roles')->where('id', $roleId)->get()->getRow()->landing_url, 'unknown pages are not stored');
        $db->table('roles')->where('id', $roleId)->update(['landing_url' => '']);

        $this->loginAs($this->makeUser('billing_operator', 'Area Billing'));
        $res = $this->get('dashboard/billing');
        $res->assertOK();
        $res->assertSee('Recent documents');
        $this->assertSame(403, $this->statusOf(fn () => $this->get('dashboard/hr')));

        $this->loginAs($this->makeUser('telecaller', 'Area Caller'));
        $this->assertSame(403, $this->statusOf(fn () => $this->get('dashboard/billing')));
        $this->assertSame(404, $this->statusOf(fn () => $this->get('dashboard/nothing')));
    }

    public function testSignInWithoutTheSessionCookieIsExplainedNotSilentlyRepeated(): void
    {
        config('Security')->redirect = true; // production behaviour
        \Config\Services::resetSingle('security');
        try {
            if (auth('session')->loggedIn()) {
                auth('session')->logout();
            }
            unset($_COOKIE[config('Cookie')->prefix . config('Session')->cookieName]);
            $res = $this->post('login', ['email' => 'nobody@landing.test', 'password' => 'x', 'csrf_test_name' => 'stale-token']);
            $res->assertRedirectTo(site_url('login') . '?session=missing');

            $page = $this->get('login?session=missing');
            $page->assertSee('Sign-in could not keep your session');
        } finally {
            config('Security')->redirect = false;
            \Config\Services::resetSingle('security');
        }
    }
}
