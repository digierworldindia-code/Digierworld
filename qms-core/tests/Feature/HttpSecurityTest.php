<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\HttpTestTrait;

/**
 * HTTP behaviour: login required, permission enforcement, CSRF and security headers.
 *
 * @internal
 */
final class HttpSecurityTest extends DatabaseTestCase
{
    use HttpTestTrait;

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
     * Next simulated request as this user.
     *
     * @param array<string, mixed> $user
     */
    private function as(array $user): static
    {
        return $this->withSession($this->sessionFor($user));
    }

    public function testPagesRequireLogin(): void
    {
        $this->get('inspections')->assertRedirectTo(site_url('login'));
        $json = $this->withHeaders(['Accept' => 'application/json'])->get('inspections/options');
        $json->assertStatus(401);
        $this->assertFalse($json->getJSON()['ok']);
    }

    public function testLoginPageSendsSecurityHeaders(): void
    {
        $response = $this->get('login');
        $response->assertOK();
        $response->assertSee('Sign in');
        $this->assertSame('DENY', $response->header('X-Frame-Options'));
        $this->assertSame('nosniff', $response->header('X-Content-Type-Options'));
        $csp = $response->header('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertNotSame('', $response->header('Referrer-Policy'));
        $this->assertNotSame('', $response->header('X-Request-Id'));
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

        foreach (['dashboard', 'inspections', 'approvals', 'templates', 'gauges', 'masters', 'admin/users', 'admin/roles', 'admin/settings', 'admin/audit', 'admin/sync', 'reports/daily', 'account/password'] as $page) {
            $this->as($this->admin)->get($page)->assertOK();
        }
    }

    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        $operator = $this->createUser('OPERATOR', 'E1001');

        $form = $this->as($operator)->post('inspections', ['report_type_id' => 1]);
        $this->assertSame(303, $form->response()->getStatusCode(), 'form posts go back with a message');
        $this->assertStringContainsString('session expired', (string) session()->getFlashdata('error'));

        $json = $this->as($operator)->withHeaders(['Accept' => 'application/json'])->post('inspections/1/draft', []);
        $json->assertStatus(403);
        $this->assertTrue($json->getJSON()['csrf']);

        $this->assertSame(0, $this->db->table('inspection_reports')->countAllResults());
    }

    public function testPostWithCsrfTokenIsAccepted(): void
    {
        $operator = $this->createUser('OPERATOR', 'E1001');
        $type     = $this->db->table('report_types')->where('code', 'SCA')->get(1)->getRowArray();
        $shifts   = service('productionCalendar')->shifts();

        $response = $this->as($operator)->post('inspections', [
            'client_uuid'     => '',
            'report_type_id'  => (string) $type['id'],
            'part_id'         => (string) $this->id('parts', 'part_number', 'BF-1001'),
            'machine_id'      => (string) $this->id('machines', 'machine_code', 'CNC-01'),
            'inspection_date' => $this->today(),
            'shift_id'        => (string) $shifts[0]['id'],
        ], true);

        $id = (int) $this->db->table('inspection_reports')->selectMax('id', 'm')->get()->getRow('m');
        $this->assertGreaterThan(0, $id);
        $response->assertRedirectTo(site_url("inspections/{$id}/edit"));
    }

    public function testRevokedSessionIsLoggedOut(): void
    {
        $operator = $this->createUser('OPERATOR', 'E1001');
        $session  = $this->sessionFor($operator);
        $session['qms_stamp'] = str_repeat('0', 32);
        Services::resetSingle('auth');
        $this->withSession($session)->get('inspections/home')->assertRedirectTo(site_url('login'));
    }

    public function testUnknownPagesAndMethods(): void
    {
        $this->get('no-such-page')->assertStatus(404);
        $this->call('POST', 'health')->assertStatus(405);
        $this->get('login', ['x' => "bad\x01value"])->assertStatus(400);
    }
}
