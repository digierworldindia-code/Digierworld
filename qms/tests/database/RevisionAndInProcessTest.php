<?php

namespace Tests\Database;

use App\Exceptions\AuthorizationException;
use App\Exceptions\RecordLockedException;
use App\Exceptions\ValidationException;
use App\Exceptions\WorkflowException;
use Tests\Support\DatabaseTestCase;

/**
 * Correction revisions of approved reports and the In-Process (shift grid) sheet.
 *
 * @internal
 */
final class RevisionAndInProcessTest extends DatabaseTestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setSettings(['workflow.reauth_on_sign' => '0']);
        $this->u = [
            'op'  => $this->createUser('OPERATOR', 'E1001'),
            'op2' => $this->createUser('OPERATOR', 'E1002'),
            'pe'  => $this->createUser('PRODUCTION_ENGINEER', 'E3001'),
            'qe'  => $this->createUser('QUALITY_ENGINEER', 'E2001'),
            'qa'  => $this->createUser('QA_ADMIN', 'E4001'),
        ];
    }

    private function approvedSca(): int
    {
        $w  = service('workflow');
        $id = $this->start('SCA', $this->u['op']);
        $this->fill($id, $this->u['op'], ['Machine Chuck Pressure' => '26.0']);
        $w->submit($id, 'DRAFT', null, $this->u['op']);
        $w->act($id, 'approve', '', '', 'PENDING_PRODUCTION', null, $this->u['pe']);
        $w->act($id, 'approve', '', '', 'PENDING_QUALITY', null, $this->u['qe']);
        $w->act($id, 'approve', '', '', 'PENDING_QA', null, $this->u['qa']);

        return $id;
    }

    public function testRevisionCopiesDataAndSupersedesOriginalWhenApproved(): void
    {
        $w        = service('workflow');
        $original = $this->approvedSca();
        $rev      = $w->revise($original, 'Obs 1 typed wrongly', null, $this->u['qa'])['report_id'];

        $copy = service('inspections')->report($rev);
        $this->assertSame(1, (int) $copy['revision_no']);
        $this->assertSame(0, (int) $copy['is_current']);
        $this->assertSame('DRAFT', $copy['status']);
        $this->assertSame(
            $this->db->table('inspection_readings rd')->join('inspection_observations o', 'o.id = rd.observation_id')->where('o.report_id', $original)->countAllResults(),
            $this->db->table('inspection_readings rd')->join('inspection_observations o', 'o.id = rd.observation_id')->where('o.report_id', $rev)->countAllResults(),
        );

        // Only one open revision per report number.
        try {
            $w->revise($original, 'Again', null, $this->u['qa']);
            $this->fail('Second open revision allowed.');
        } catch (WorkflowException) {
        }

        $this->fill($rev, $this->u['qa'], ['Machine Chuck Pressure' => '24.0']);
        $w->submit($rev, 'DRAFT', null, $this->u['qa']);
        $w->act($rev, 'approve', '', '', 'PENDING_PRODUCTION', null, $this->u['pe']);
        $w->act($rev, 'approve', '', '', 'PENDING_QUALITY', null, $this->u['qe']);
        $w->act($rev, 'approve', 'Correction approved', '', 'PENDING_QA', null, $this->admin);

        $old = service('inspections')->report($original);
        $new = service('inspections')->report($rev);
        $this->assertSame('SUPERSEDED', $old['status']);
        $this->assertSame(0, (int) $old['is_current']);
        $this->assertSame('QA_APPROVED', $new['status']);
        $this->assertSame(1, (int) $new['is_current']);
        $this->assertSame($old['report_no'], $new['report_no']);
        $this->assertSame('PASS', $new['overall_result']);
        $this->assertSame('FAIL', $old['overall_result'], 'the original record is kept unchanged');
    }

    public function testOnlyApprovedCurrentReportsCanBeRevised(): void
    {
        $id = $this->start('SCA', $this->u['op']);
        $this->expectException(WorkflowException::class);
        service('workflow')->revise($id, 'reason', null, $this->u['qa']);
    }

    public function testOperatorsCannotCreateRevisions(): void
    {
        $id = $this->approvedSca();
        $this->expectException(AuthorizationException::class);
        service('workflow')->revise($id, 'reason', null, $this->u['op']);
    }

    public function testInProcessSheetIsSharedPerPartMachineAndDay(): void
    {
        $sheet = $this->start('IPR', $this->u['op']);
        $this->assertSame($sheet, $this->start('IPR', $this->u['op2']), 'the second operator joins the open day sheet');
        $this->assertNotSame($sheet, $this->start('IPR', $this->u['op'], 'CNC-02'));
        $this->assertSame(0, $this->db->table('inspection_rounds')->where('report_id', $sheet)->countAllResults());
    }

    public function testRoundsAreSignedByTheirOperatorAndVerifiedByAnotherPerson(): void
    {
        $service = service('inspections');
        $shifts  = service('productionCalendar')->shifts();
        $sheet   = $this->start('IPR', $this->u['op']);
        $r1      = $service->addRound($sheet, (int) $shifts[0]['id'], '07:00', $this->u['op']);
        $r2      = $service->addRound($sheet, (int) $shifts[1]['id'], '15:00', $this->u['op2']);
        $this->fill($sheet, $this->u['op'], [], $r1);

        // op2 cannot edit or sign op's round.
        try {
            $this->fill($sheet, $this->u['op2'], [], $r1);
            $this->fail('Another operator edited a foreign round.');
        } catch (AuthorizationException) {
        }
        try {
            $service->signRound($sheet, $r2, $this->u['op2']);
            $this->fail('Incomplete round signed.');
        } catch (ValidationException) {
        }

        $service->signRound($sheet, $r1, $this->u['op']);
        try {
            $this->fill($sheet, $this->u['op'], [], $r1);
            $this->fail('Signed round edited.');
        } catch (RecordLockedException) {
        }

        // Submission needs every round signed.
        try {
            service('workflow')->submit($sheet, 'DRAFT', null, $this->u['op']);
            $this->fail('Sheet with an unsigned round submitted.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not signed', implode(' ', $e->errors()));
        }

        $this->fill($sheet, $this->u['op2'], [], $r2);
        $service->signRound($sheet, $r2, $this->u['op2']);

        // The QE cannot be the operator who signed (also enforced by a CHECK constraint).
        $qeOperator = $this->createUser('QUALITY_ENGINEER', null, 'qe_and_operator');
        $this->db->table('inspection_rounds')->where('id', $r1)->update(['operator_user_id' => $qeOperator['id']]);
        try {
            $service->verifyRound($sheet, $r1, '', $qeOperator);
            $this->fail('QE verified own round.');
        } catch (AuthorizationException) {
        }
        $service->verifyRound($sheet, $r2, '', $this->u['qe']);
        $this->assertSame('VERIFIED', $this->db->table('inspection_rounds')->where('id', $r2)->get()->getRow('status'));

        $result = service('workflow')->submit($sheet, 'DRAFT', null, $this->u['op']);
        $this->assertSame('PENDING_QUALITY', $result['status'], 'In-Process skips production verification by default');
        $this->assertStringStartsWith('IPR-', $result['report_no']);
    }

    public function testReopenNeedsReasonAndClearsVerification(): void
    {
        $service = service('inspections');
        $sheet   = $this->start('IPR', $this->u['op']);
        $round   = $service->addRound($sheet, (int) service('productionCalendar')->shifts()[0]['id'], '08:00', $this->u['op']);
        $this->fill($sheet, $this->u['op'], [], $round);
        $service->signRound($sheet, $round, $this->u['op']);
        $service->verifyRound($sheet, $round, '', $this->u['qe']);

        try {
            $service->reopenRound($sheet, $round, '  ', $this->u['qe']);
            $this->fail('Reopened without reason.');
        } catch (ValidationException) {
        }
        $service->reopenRound($sheet, $round, 'Wrong time recorded', $this->u['qe']);
        $row = $this->db->table('inspection_rounds')->where('id', $round)->get()->getRowArray();
        $this->assertSame('OPEN', $row['status']);
        $this->assertNull($row['qe_user_id']);
    }
}
