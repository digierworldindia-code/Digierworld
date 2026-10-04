<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\DatabaseTestCase;
use Tests\Support\HttpTestTrait;
use Tests\Support\TestResponse;

/**
 * Administration forms through the real routes, filters, CSRF and controllers:
 * templates, users, roles, master data and the Google Sheets queue.
 *
 * @internal
 */
final class AdminFormsTest extends DatabaseTestCase
{
    use HttpTestTrait;

    /**
     * POST as the Super Admin with a valid CSRF token; asserts a redirect with a success message.
     *
     * @param array<string, mixed> $data
     */
    private function submit(string $path, array $data = [], string $expect = 'success'): TestResponse
    {
        $_SESSION = ['qms_uid' => $this->admin['id'], 'qms_stamp' => $this->admin['security_stamp'], 'qms_login_at' => time(), 'qms_last_seen' => time()];
        $response = $this->post($path, $data, true);
        $this->assertSame(303, $response->response()->getStatusCode(), "POST {$path}");
        $error = session()->getFlashdata('error');
        if ($expect === 'success') {
            $this->assertNull($error, "POST {$path}: " . json_encode([$error, session()->getFlashdata('errors')]));
            $this->assertIsString(session()->getFlashdata('success'), "POST {$path} success message");
        } else {
            $this->assertIsString($error, "POST {$path} must fail");
        }

        return $response;
    }

    private function lastId(string $table): int
    {
        return (int) $this->db->table($table)->selectMax('id', 'm')->get()->getRow('m');
    }

    public function testTemplateLifecycle(): void
    {
        $ppi = $this->id('report_types', 'code', 'PPI');
        $this->submit('templates', ['report_type_id' => (string) $ppi, 'template_code' => 'ppi-test', 'name' => 'Test PPI', 'format_doc_no' => 'QA/F/09', 'format_rev_no' => '00', 'format_made_date' => '2026-01-01', 'format_rev_date' => '', 'planned_rounds_per_shift' => '', 'notes' => '']);
        $template = $this->lastId('inspection_templates');
        $this->assertSame('PPI-TEST', $this->db->table('inspection_templates')->where('id', $template)->get(1)->getRow('template_code'));

        $this->submit("templates/{$template}/sections", ['title' => 'Dimensions', 'description' => '']);
        $section = $this->lastId('template_sections');

        $param = [
            'id' => '', 'section_id' => (string) $section, 'parameter_id' => (string) $this->lastId('inspection_parameters'), 'name' => 'Bore diameter',
            'specification_text' => '', 'observation_type' => 'NUMERIC', 'unit_id' => '', 'nominal' => '', 'lsl' => '12.50', 'usl' => '12.40',
            'decimal_places' => '2', 'date_rule' => 'NONE', 'inspection_method_id' => '', 'gauge_type_id' => '', 'gauge_required' => '0',
            'observation_count' => '3', 'is_mandatory' => '1', 'prefill_source' => 'NONE', 'help_text' => '',
        ];
        $this->submit("templates/{$template}/parameters", $param, 'error');
        $this->assertSame('USL must be greater than or equal to LSL.', session()->getFlashdata('errors')['usl'] ?? null);
        $this->submit("templates/{$template}/parameters", ['lsl' => '12.40', 'usl' => '12.50'] + $param);
        $this->assertSame('12.400000', $this->db->table('template_parameters')->where('section_id', $section)->get(1)->getRow('lsl'));

        $this->submit("templates/{$template}/mapping", ['parts' => [(string) $this->id('parts', 'part_number', 'BF-1001')], 'machines' => [(string) $this->id('machines', 'machine_code', 'CNC-01')]]);
        $this->submit("templates/{$template}/publish");
        $this->assertSame('PUBLISHED', $this->db->table('inspection_templates')->where('id', $template)->get(1)->getRow('status'));

        $this->submit("templates/{$template}/new-version");
        $draft = $this->lastId('inspection_templates');
        $this->assertSame(['DRAFT', '2'], array_values($this->db->table('inspection_templates')->select('status, version')->where('id', $draft)->get(1)->getRowArray()));
        $this->assertSame(1, $this->db->table('template_parameters tp')->join('template_sections s', 's.id = tp.section_id')->where('s.template_id', $draft)->countAllResults());
        $this->submit("templates/{$draft}/delete");
        $this->assertSame(0, $this->db->table('inspection_templates')->where('id', $draft)->countAllResults());
    }

    public function testUserAndRoleAdministration(): void
    {
        $operatorRole = $this->id('roles', 'code', 'OPERATOR');
        $this->submit('admin/users', ['username' => 'line.op7', 'email' => 'op7@example.com', 'role_id' => (string) $operatorRole, 'employee_id' => (string) $this->id('employees', 'employee_code', 'E1002')]);
        $user = $this->db->table('users')->where('username', 'line.op7')->get(1)->getRowArray();
        $this->assertSame('1', $user['must_change_password'], 'new accounts get a one-time password');

        $this->submit("admin/users/{$user['id']}/reset-password");
        $this->assertNotSame($user['password_hash'], $this->db->table('users')->where('id', $user['id'])->get(1)->getRow('password_hash'));
        $this->submit("admin/users/{$user['id']}/status");
        $this->assertSame('DISABLED', $this->db->table('users')->where('id', $user['id'])->get(1)->getRow('status'));
        $this->submit("admin/users/{$user['id']}/unlock");

        $this->submit('admin/roles', ['code' => 'line_lead', 'name' => 'Line lead', 'description' => 'Shift lead']);
        $role = $this->id('roles', 'code', 'LINE_LEAD');
        $this->submit("admin/roles/{$role}", ['name' => 'Line lead', 'description' => 'Shift lead', 'permissions' => ['dashboard.view', 'report.view']]);
        $this->assertSame(2, $this->db->table('role_permissions')->where('role_id', $role)->countAllResults());
        $this->submit("admin/roles/{$role}/delete");
        $this->assertSame(0, $this->db->table('roles')->where('id', $role)->countAllResults());
    }

    public function testMasterToggleAndSyncQueueActions(): void
    {
        $machine = $this->id('machines', 'machine_code', 'CNC-01');
        $this->submit("masters/machines/{$machine}/toggle");
        $this->assertSame('0', $this->db->table('machines')->where('id', $machine)->get(1)->getRow('is_active'));
        $this->submit("masters/machines/{$machine}", ['machine_name' => 'CNC lathe 1 (rebuilt)', 'department_id' => '', 'make' => '', 'model' => '', 'location' => 'Bay 2', 'pm_due_date' => '']);
        $this->assertSame('CNC lathe 1 (rebuilt)', $this->db->table('machines')->where('id', $machine)->get(1)->getRow('machine_name'));

        $this->setSettings(['google.spreadsheet_id' => 'TestSpreadsheetId_0123456789', 'google.sync_enabled' => 1]);
        $this->submit('admin/sync/backfill', ['from' => date('Y-m-d', strtotime($this->today() . ' -30 days')), 'to' => $this->today()]);
        $this->submit('admin/sync/retry-failed');
    }
}
