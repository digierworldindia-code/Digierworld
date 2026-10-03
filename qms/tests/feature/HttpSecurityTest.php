<?php

namespace Tests\Feature;

use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\DatabaseTestCase;

/**
 * HTTP behaviour: login required, permission enforcement, CSRF and security headers.
 *
 * @internal
 */
final class HttpSecurityTest extends DatabaseTestCase
{
    use FeatureTestTrait;

    /**
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    private function sessionFor(array $user): array
    {
        return ['qms_uid' => $user['id'], 'qms_stamp' => $user['security_stamp'], 'qms_login_at' => time(), 'qms_last_seen' => time()];
    }

    /**
     * Next simulated request as this user. Real requests are separate processes;
     * here the per-request services that remember the user are rebuilt.
     *
     * @param array<string, mixed> $user
     */
    private function as(array $user): static
    {
        $this->resetUserServices();

        return $this->withSession($this->sessionFor($user));
    }

    public function testPagesRequireLogin(): void
    {
        $this->get('inspections')->assertRedirectTo(site_url('login'));
        $json = $this->withHeaders(['Accept' => 'application/json'])->get('inspections/options');
        $json->assertStatus(401);
        $this->assertSame(false, json_decode($json->getJSON(), true)['ok']);
    }

    public function testLoginPageSendsSecurityHeaders(): void
    {
        $response = $this->get('login');
        $response->assertOK();
        $response->assertSee('Sign in');
        $this->assertSame('DENY', $response->response()->getHeaderLine('X-Frame-Options'));
        $this->assertSame('nosniff', $response->response()->getHeaderLine('X-Content-Type-Options'));
        // CodeIgniter adds the CSP header when the response is sent; finalise it here.
        $http = $response->response();
        $http->getCSP()->finalize($http);
        $csp = $http->getHeaderLine('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertNotSame('', $response->response()->getHeaderLine('Referrer-Policy'));
    }

    public function testPermissionsAreEnforcedPerRoute(): void
    {
        $operator = $this->createUser('OPERATOR', 'E1001');
        $this->as($operator)->get('inspections/home')->assertOK();
        $this->as($operator)->get('admin/users')->assertStatus(403);
        $this->as($operator)->get('templates')->assertStatus(403);
        $this->as($operator)->get('dashboard')->assertStatus(403);
        $this->assertGreaterThan(0, $this->db->table('audit_logs')->where('action', 'ACCESS_DENIED')->countAllResults(), 'denied attempts are audited');

        $viewer = $this->createUser('VIEWER');
        $this->as($viewer)->get('dashboard')->assertOK();
        $this->as($viewer)->get('reports/oos')->assertOK();
        $this->as($viewer)->get('inspections/new')->assertStatus(403);
        $this->as($viewer)->get('admin/settings')->assertStatus(403);

        $admin = $this->admin;
        foreach (['dashboard', 'inspections', 'approvals', 'templates', 'gauges', 'masters', 'admin/users', 'admin/roles', 'admin/settings', 'admin/audit', 'admin/sync', 'reports/daily'] as $page) {
            $this->as($admin)->get($page)->assertOK();
        }
    }

    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        $operator = $this->createUser('OPERATOR', 'E1001');
        try {
            $this->as($operator)->post('inspections', ['report_type_id' => 1]);
            $this->fail('POST without CSRF token was accepted.');
        } catch (SecurityException $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->assertSame(0, $this->db->table('inspection_reports')->countAllResults());
    }

    public function testRevokedSessionIsLoggedOut(): void
    {
        $operator = $this->createUser('OPERATOR', 'E1001');
        $session  = $this->sessionFor($operator);
        $session['qms_stamp'] = str_repeat('0', 32);
        Services::resetSingle('auth');
        $this->withSession($session)->get('inspections/home')->assertRedirectTo(site_url('login'));
    }
}
