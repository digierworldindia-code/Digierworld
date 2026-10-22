<?php

namespace Tests\Feature;

use Tests\Support\DgrTestCase;

/**
 * Reported on a live install: after signing in, users landed on
 * /brand/logo (a 404 there) instead of the dashboard. The sign-in page
 * loads the logo, the logo URL required a session, and Shield remembered
 * that image request as the page to return to after sign-in.
 */
final class SignInFlowTest extends DgrTestCase
{
    public function testSignInPageLogoIsPublicAndOnlyPageVisitsBecomeTheReturnTarget(): void
    {
        $owner = $this->makeUser('super_admin', 'Flow Owner');
        $this->brandAsset('logo', (int) $owner->id);
        if (auth('session')->loggedIn()) {
            auth('session')->logout(); // an earlier test's sign-in may still be active in this process
        }

        // signed out: the sign-in page shows the logo and the logo itself is served
        $this->get('login')->assertSee('brand/logo');
        $logo = $this->get('brand/logo');
        $logo->assertOK();
        $logo->assertHeader('Content-Type', 'image/png');
        $this->assertNull(session()->getTempdata('beforeLoginUrl'));

        // signed out: images and script calls get 401, and are never remembered as the return page
        foreach ([['Sec-Fetch-Dest' => 'image'], ['Sec-Fetch-Dest' => 'empty'], ['X-Requested-With' => 'XMLHttpRequest']] as $headers) {
            $res = $this->withHeaders($headers)->get('billing/documents');
            $this->assertSame(401, $res->response()->getStatusCode(), json_encode($headers));
            $this->assertStringContainsString('session has ended', (string) $res->response()->getBody());
            $this->assertNull(session()->getTempdata('beforeLoginUrl'), json_encode($headers));
        }

        // a real page visit still goes to sign-in and is remembered, so the user comes back to it
        $res = $this->withHeaders(['Sec-Fetch-Dest' => 'document'])->get('billing/documents');
        $res->assertRedirectTo(site_url('login'));
        $this->assertStringEndsWith('billing/documents', (string) session()->getTempdata('beforeLoginUrl'));
    }

    public function testHomeOpensTheFirstModuleOfARoleWithoutTheDashboard(): void
    {
        $db = db_connect();
        $db->table('roles')->insert(['code' => 'receipts_clerk_' . bin2hex(random_bytes(3)), 'name' => 'Receipts clerk', 'is_super' => 0, 'is_system' => 0, 'is_active' => 1]);
        $roleId = (int) $db->insertID();
        $db->table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => (int) $db->table('permissions')->where('perm_key', 'finance.receipt.view')->get()->getRow()->id]);
        $clerk = $this->makeUser((string) $db->table('roles')->where('id', $roleId)->get()->getRow()->code, 'Receipts Clerk');
        $this->loginAs($clerk);
        $this->get('/')->assertRedirectTo(site_url('finance/receipts'));
        $this->assertSame(403, $this->statusOf(fn () => $this->get('dashboard/data')), 'dashboard data still needs dashboard.view');

        $db->table('role_permissions')->where('role_id', $roleId)->delete();
        service('authz')->forget();
        $res = $this->get('/');
        $res->assertOK();
        $res->assertSee('No modules are assigned to your account yet');

        $this->loginAs($this->makeUser('employee', 'Plain Employee'));
        $res = $this->get('/');
        $res->assertOK();
        $res->assertSee('Dashboard');
    }
}
