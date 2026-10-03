<?php

namespace Tests\Database;

use CodeIgniter\Database\Exceptions\DatabaseException;
use Tests\Support\DatabaseTestCase;

/**
 * Database-level guards that hold even if application code is wrong
 * (triggers and CHECK constraints in database/schema/qms_schema.sql).
 *
 * @internal
 */
final class IntegrityTest extends DatabaseTestCase
{
    private int $reportId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setSettings(['workflow.reauth_on_sign' => '0']);
        $op             = $this->createUser('OPERATOR', 'E1001');
        $this->reportId = $this->start('SCA', $op);
        $this->fill($this->reportId, $op);
        service('workflow')->submit($this->reportId, 'DRAFT', null, $op);
    }

    private function assertLocked(callable $query): void
    {
        try {
            $query();
            $this->fail('The database accepted a forbidden change.');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('QMS-LOCK', $e->getMessage());
        }
    }

    public function testSubmittedReadingsCannotBeChanged(): void
    {
        $reading = $this->db->table('inspection_readings rd')->select('rd.id')
            ->join('inspection_observations o', 'o.id = rd.observation_id')->where('o.report_id', $this->reportId)->get(1)->getRow('id');

        $this->assertLocked(fn () => $this->db->table('inspection_readings')->where('id', $reading)->update(['value_text' => null, 'value_choice' => 'NOT_OK', 'result' => 'FAIL']));
        $this->assertLocked(fn () => $this->db->table('inspection_readings')->where('id', $reading)->delete());
        $this->assertLocked(fn () => $this->db->table('inspection_observations')->where('report_id', $this->reportId)->update(['remarks' => 'changed']));
        $this->assertLocked(fn () => $this->db->table('inspection_reports')->where('id', $this->reportId)->update(['overall_result' => 'PASS', 'oos_count' => 0, 'part_id' => 1, 'remarks' => 'x']));
    }

    public function testReportsAreNeverDeleted(): void
    {
        $this->assertLocked(fn () => $this->db->table('inspection_reports')->where('id', $this->reportId)->delete());
    }

    public function testEvidenceTablesAreAppendOnly(): void
    {
        $this->assertLocked(fn () => $this->db->table('inspection_approvals')->where('report_id', $this->reportId)->update(['remarks' => 'edited']));
        $this->assertLocked(fn () => $this->db->table('inspection_approvals')->where('report_id', $this->reportId)->delete());
        $this->assertLocked(fn () => $this->db->table('audit_logs')->update(['action' => 'X']));
        $this->assertLocked(fn () => $this->db->table('audit_logs')->where('id >', 0)->delete());
        $this->db->table('login_logs')->insert(['username_attempted' => 'x', 'event' => 'LOGIN_FAILED', 'ip_address' => '127.0.0.1', 'created_at' => gmdate('Y-m-d H:i:s')]);
        $this->assertLocked(fn () => $this->db->table('login_logs')->where('id >', 0)->delete());
        $this->assertLocked(fn () => $this->db->table('login_logs')->update(['username_attempted' => 'y']));
    }

    public function testPublishedTemplatesAreFrozen(): void
    {
        $param = $this->db->table('template_parameters tp')->select('tp.id')->join('template_sections s', 's.id = tp.section_id')
            ->join('inspection_templates t', 't.id = s.template_id')->where('t.status', 'PUBLISHED')->get(1)->getRow('id');
        $this->assertLocked(fn () => $this->db->table('template_parameters')->where('id', $param)->update(['usl' => '999']));
    }

    public function testApprovedReportOnlyMovesToSuperseded(): void
    {
        $users = [$this->createUser('PRODUCTION_ENGINEER', 'E3001'), $this->createUser('QUALITY_ENGINEER', 'E2001'), $this->createUser('QA_ADMIN', 'E4001')];
        $w     = service('workflow');
        $w->act($this->reportId, 'approve', '', '', 'PENDING_PRODUCTION', null, $users[0]);
        $w->act($this->reportId, 'approve', '', '', 'PENDING_QUALITY', null, $users[1]);
        $w->act($this->reportId, 'approve', '', '', 'PENDING_QA', null, $users[2]);

        $this->assertLocked(fn () => $this->db->table('inspection_reports')->where('id', $this->reportId)->update(['status' => 'DRAFT']));
        $this->assertLocked(fn () => $this->db->table('inspection_reports')->where('id', $this->reportId)->update(['status' => 'REJECTED']));
    }

    public function testCheckConstraintsRejectImpossibleData(): void
    {
        $this->expectException(DatabaseException::class);
        // LSL above USL is refused by chk_template_parameters_limits even on a draft template.
        $draft = service('templates')->newVersion((int) $this->db->table('inspection_templates')->where('template_code', 'SCA-STD')->get()->getRow('id'), $this->admin);
        $param = $this->db->table('template_parameters tp')->select('tp.id')->join('template_sections s', 's.id = tp.section_id')
            ->where('s.template_id', $draft)->where('tp.observation_type', 'NUMERIC')->where('tp.lsl IS NOT NULL')->get(1)->getRow('id');
        $this->db->table('template_parameters')->where('id', $param)->update(['lsl' => '10', 'usl' => '5']);
    }
}
