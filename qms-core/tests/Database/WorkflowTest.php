<?php

namespace Tests\Database;

use App\Exceptions\AuthorizationException;
use App\Exceptions\ConcurrencyException;
use App\Exceptions\ValidationException;
use App\Exceptions\WorkflowException;
use App\Libraries\Uuid;
use Tests\Support\DatabaseTestCase;

/**
 * Report state machine, numbering, signatures and segregation of duties
 * (docs/architecture/07-workflow.md).
 *
 * @internal
 */
final class WorkflowTest extends DatabaseTestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setSettings(['workflow.reauth_on_sign' => '0']);
        $this->u = [
            'op' => $this->createUser('OPERATOR', 'E1001'),
            'pe' => $this->createUser('PRODUCTION_ENGINEER', 'E3001'),
            'qe' => $this->createUser('QUALITY_ENGINEER', 'E2001'),
            'qa' => $this->createUser('QA_ADMIN', 'E4001'),
        ];
    }

    private function submitted(): int
    {
        $id = $this->start('SCA', $this->u['op']);
        $this->assertTrue($this->fill($id, $this->u['op'])['ok']);
        service('workflow')->submit($id, 'DRAFT', null, $this->u['op']);

        return $id;
    }

    public function testFullApprovalChainAllocatesNumberAndSignatures(): void
    {
        $workflow = service('workflow');
        $id       = $this->start('SCA', $this->u['op']);
        $saved    = $this->fill($id, $this->u['op']);
        $this->assertTrue($saved['ok'], json_encode($saved['errors']));
        $this->assertSame([], $saved['problems']);

        $result = $workflow->submit($id, 'DRAFT', Uuid::v4(), $this->u['op']);
        $this->assertSame('PENDING_PRODUCTION', $result['status']);
        $this->assertMatchesRegularExpression('/^SCA-\d{4}-\d{2}-000001$/', $result['report_no']);

        $workflow->act($id, 'approve', '', '', 'PENDING_PRODUCTION', null, $this->u['pe']);
        $workflow->act($id, 'approve', '', '', 'PENDING_QUALITY', null, $this->u['qe']);
        $final = $workflow->act($id, 'approve', 'OK', '', 'PENDING_QA', null, $this->u['qa']);
        $this->assertSame('QA_APPROVED', $final['status']);

        $report = service('inspections')->report($id);
        $this->assertSame('PASS', $report['overall_result']);
        $this->assertSame($this->u['qa']['id'], (int) $report['approved_by']);
        $actions = array_column($this->db->table('inspection_approvals')->where('report_id', $id)->orderBy('id')->get()->getResultArray(), 'action');
        $this->assertSame(['SUBMITTED', 'APPROVED', 'APPROVED', 'APPROVED'], $actions);
        $this->assertSame('SIGNED', $this->db->table('inspection_rounds')->where('report_id', $id)->get()->getRow('status'), 'submission signs the round');

        // A second report gets the next number of the period.
        $second = $this->submitted();
        $this->assertStringEndsWith('-000002', (string) service('inspections')->report($second)['report_no']);
    }

    public function testSubmitterCannotVerifyAndDistinctSignersApply(): void
    {
        $workflow = service('workflow');
        // The QA admin creates and submits the report.
        $id = $this->start('SCA', $this->u['qa']);
        $this->fill($id, $this->u['qa']);
        $workflow->submit($id, 'DRAFT', null, $this->u['qa']);

        // Super Admin (all permissions) signs production verification.
        $workflow->act($id, 'approve', '', '', 'PENDING_PRODUCTION', null, $this->admin);

        try {
            $workflow->act($id, 'approve', '', '', 'PENDING_QUALITY', null, $this->u['qa']);
            $this->fail('Submitter signed a verification stage.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('submitted this report', $e->getMessage());
        }

        // With distinct signers on, the Super Admin may not sign a second stage.
        try {
            $workflow->act($id, 'approve', '', '', 'PENDING_QUALITY', null, $this->admin);
            $this->fail('One person signed two stages.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('another stage', $e->getMessage());
        }

        $this->assertSame('PENDING_QA', $workflow->act($id, 'approve', '', '', 'PENDING_QUALITY', null, $this->u['qe'])['status']);
    }

    public function testRolesWithoutTheStagePermissionCannotSign(): void
    {
        $id = $this->submitted();
        $this->expectException(AuthorizationException::class);
        service('workflow')->act($id, 'approve', '', '', 'PENDING_PRODUCTION', null, $this->u['qe']);
    }

    public function testReturnReopensForCorrectionAndResubmitKeepsNumber(): void
    {
        $workflow = service('workflow');
        $id       = $this->submitted();
        $number   = service('inspections')->report($id)['report_no'];

        try {
            $workflow->act($id, 'return', '', '', 'PENDING_PRODUCTION', null, $this->u['pe']);
            $this->fail('Return without remarks accepted.');
        } catch (ValidationException) {
        }
        $workflow->act($id, 'return', 'Chuck pressure not recorded correctly', '', 'PENDING_PRODUCTION', null, $this->u['pe']);
        $report = service('inspections')->report($id);
        $this->assertSame('RETURNED', $report['status']);
        $this->assertSame('OPEN', $this->db->table('inspection_rounds')->where('report_id', $id)->get()->getRow('status'));
        $this->assertTrue(service('inspections')->canEdit($report, $this->u['op']));

        $this->assertTrue($this->fill($id, $this->u['op'], ['Machine Chuck Pressure' => '22.0'])['ok']);
        $again = $workflow->submit($id, 'RETURNED', null, $this->u['op']);
        $this->assertSame('PENDING_PRODUCTION', $again['status']);
        $this->assertSame($number, $again['report_no'], 'resubmission keeps the report number');
    }

    public function testRejectionIsFinal(): void
    {
        $workflow = service('workflow');
        $id       = $this->submitted();
        $workflow->act($id, 'reject', 'Wrong part loaded', '', 'PENDING_PRODUCTION', null, $this->u['pe']);
        $this->assertSame('REJECTED', service('inspections')->report($id)['status']);

        $this->expectException(WorkflowException::class);
        $workflow->act($id, 'approve', '', '', 'REJECTED', null, $this->u['qe']);
    }

    public function testStaleScreenIsRefused(): void
    {
        $id = $this->submitted();
        service('workflow')->act($id, 'approve', '', '', 'PENDING_PRODUCTION', null, $this->u['pe']);

        $this->expectException(ConcurrencyException::class);
        service('workflow')->act($id, 'approve', '', '', 'PENDING_PRODUCTION', null, $this->u['admin'] ?? $this->admin);
    }

    public function testSubmitIsIdempotent(): void
    {
        $id  = $this->start('SCA', $this->u['op']);
        $this->fill($id, $this->u['op']);
        $key = Uuid::v4();

        $first  = service('workflow')->submit($id, 'DRAFT', $key, $this->u['op']);
        $second = service('workflow')->submit($id, 'DRAFT', $key, $this->u['op']);

        $this->assertSame($first['report_no'], $second['report_no']);
        $this->assertTrue($second['replayed'] ?? false);
        $this->assertSame(1, $this->db->table('inspection_approvals')->where('report_id', $id)->where('action', 'SUBMITTED')->countAllResults());
    }

    public function testIncompleteReportCannotBeSubmitted(): void
    {
        $id = $this->start('SCA', $this->u['op']);
        try {
            service('workflow')->submit($id, 'DRAFT', null, $this->u['op']);
            $this->fail('Incomplete report submitted.');
        } catch (ValidationException $e) {
            $problems = implode(' ', $e->errors());
            $this->assertStringContainsString('missing readings', $problems);
            $this->assertStringContainsString('finish time', $problems);
        }
        $this->assertSame('DRAFT', service('inspections')->report($id)['status']);
        $this->assertNull(service('inspections')->report($id)['report_no'], 'no number is used up by a failed submission');
    }

    public function testCancelNeedsReasonAndPermission(): void
    {
        $id = $this->start('SCA', $this->u['op']);
        try {
            service('workflow')->cancel($id, 'Wrong machine', 'DRAFT', $this->u['pe']);
            $this->fail('Production engineer cancelled an operator draft.');
        } catch (AuthorizationException) {
        }
        $result = service('workflow')->cancel($id, 'Wrong machine', 'DRAFT', $this->u['op']);
        $this->assertSame('CANCELLED', $result['status']);
        $this->assertSame('Wrong machine', $this->db->table('inspection_approvals')->where('report_id', $id)->get()->getRow('remarks'));
    }

    public function testOutOfSpecificationIsDetectedAndCounted(): void
    {
        $id    = $this->start('SCA', $this->u['op']);
        $saved = $this->fill($id, $this->u['op'], ['Machine Chuck Pressure' => '26.0']);

        $this->assertSame('FAIL', $saved['overall_result']);
        $this->assertSame(1, $saved['oos_count']);
        // A failing report can still be submitted: the verdict is recorded, approvers decide.
        $this->assertSame('PENDING_PRODUCTION', service('workflow')->submit($id, 'DRAFT', null, $this->u['op'])['status']);
    }

    public function testClientCannotChangeSpecificationLimits(): void
    {
        $id  = $this->start('SCA', $this->u['op']);
        $obs = $this->db->table('inspection_observations o')->select('o.id, o.lsl, o.usl')
            ->join('template_parameters tp', 'tp.id = o.template_parameter_id')->where('o.report_id', $id)->where('tp.name', 'Machine Chuck Pressure')->get(1)->getRowArray();

        $result = service('inspections')->saveDraft($id, ['observations' => [['observation_id' => $obs['id'], 'lsl' => '0', 'usl' => '999', 'remarks' => 'x']],
            'cells' => [['observation_id' => $obs['id'], 'reading_no' => 1, 'value' => '30']]], $this->u['op']);

        $after = $this->db->table('inspection_observations')->where('id', $obs['id'])->get(1)->getRowArray();
        $this->assertSame($obs['lsl'], $after['lsl']);
        $this->assertSame($obs['usl'], $after['usl']);
        $this->assertSame('FAIL', $result['observations'][(int) $obs['id']]['readings'][1]);
    }

    public function testInvalidReadingIsReportedAndOthersAreSaved(): void
    {
        $id    = $this->start('SCA', $this->u['op']);
        $cells = $this->db->table('inspection_observations o')->select('o.id, tp.name')
            ->join('template_parameters tp', 'tp.id = o.template_parameter_id')->where('o.report_id', $id)
            ->whereIn('tp.name', ['Machine Chuck Pressure', 'Machine System Pressure'])->get()->getResultArray();
        $byName = array_column($cells, 'id', 'name');

        $result = service('inspections')->saveDraft($id, ['cells' => [
            ['observation_id' => $byName['Machine Chuck Pressure'], 'reading_no' => 1, 'value' => '21.123'],
            ['observation_id' => $byName['Machine System Pressure'], 'reading_no' => 1, 'value' => '50.0'],
        ]], $this->u['op']);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('cell-' . $byName['Machine Chuck Pressure'] . '-1', $result['errors']);
        $this->assertSame('PASS', $result['observations'][(int) $byName['Machine System Pressure']]['readings'][1]);
    }

    public function testExpiredGaugeBlocksSubmission(): void
    {
        $id      = $this->start('PPI', $this->u['op']);
        $this->fill($id, $this->u['op']);
        $expired = $this->id('gauges', 'gauge_code', 'DVC-002');
        $obs     = $this->db->table('inspection_observations o')->select('o.id')
            ->join('template_parameters tp', 'tp.id = o.template_parameter_id')->where('o.report_id', $id)->where('tp.name', 'Throat Diameter')->get(1)->getRow('id');
        service('inspections')->saveDraft($id, ['observations' => [['observation_id' => $obs, 'gauge_id' => $expired]]], $this->u['op']);

        try {
            service('workflow')->submit($id, 'DRAFT', null, $this->u['op']);
            $this->fail('Submitted with an expired gauge.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('DVC-002', implode(' ', $e->errors()));
        }

        $this->setSettings(['gauge.block_expired_on_submit' => '0']);
        $this->assertSame('PENDING_PRODUCTION', service('workflow')->submit($id, 'DRAFT', null, $this->u['op'])['status']);
        $this->assertSame('0', (string) $this->db->table('inspection_observations')->where('id', $obs)->get()->getRow('gauge_cal_valid'));
    }

    public function testPasswordIsRequiredToSignWhenConfigured(): void
    {
        $this->setSettings(['workflow.reauth_on_sign' => '1']);
        $id = $this->submitted();
        $this->actingAs($this->u['pe']);

        try {
            service('workflow')->act($id, 'approve', '', 'wrong-password', 'PENDING_PRODUCTION', null, $this->u['pe']);
            $this->fail('Signed with a wrong password.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('password', $e->getMessage());
        }
        $result = service('workflow')->act($id, 'approve', '', 'Correct-Horse-91', 'PENDING_PRODUCTION', null, $this->u['pe']);
        $this->assertSame('PENDING_QUALITY', $result['status']);
        $this->assertSame('1', (string) $this->db->table('inspection_approvals')->where('report_id', $id)->where('action', 'APPROVED')->get()->getRow('reauthenticated'));
    }
}
