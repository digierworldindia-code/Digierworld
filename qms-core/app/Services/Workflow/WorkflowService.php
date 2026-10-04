<?php

namespace App\Services\Workflow;

use App\Enums\ReportStatus;
use App\Enums\WorkflowStage;
use App\Exceptions\AuthorizationException;
use App\Exceptions\ConcurrencyException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Uuid;
use App\Exceptions\WorkflowException;
use App\Libraries\Paging;
use App\Services\Access\AuthorizationService;
use App\Services\Audit\AuditService;
use App\Services\Auth\AuthService;
use App\Services\Inspections\InspectionService;
use App\Services\Numbering\DocumentNumberService;
use App\Services\Settings\SettingsService;
use App\Services\Support\Clock;
use App\Services\Support\IdempotencyService;
use App\Services\Support\RequestContext;
use App\Services\Support\TransactionRunner;
use App\Services\Sync\SheetSyncQueue;
use App\Core\Database;

/**
 * Report state machine (docs/architecture/07-workflow.md §7.2).
 *
 * Every transition runs in one transaction: the report row is locked FOR UPDATE,
 * the status the user saw is compared (stale screens get 409), permission and
 * segregation of duties are checked, one append-only signature row is written,
 * plus the audit entry and the Google Sheets outbox job. Submit and approval
 * requests are idempotent (Idempotency-Key).
 */
class WorkflowService
{
    public const ACTIONS = ['approve', 'return', 'reject'];

    private const OPEN_STATUSES = ['DRAFT', 'PENDING_PRODUCTION', 'PENDING_QUALITY', 'PENDING_QA', 'RETURNED'];

    public function __construct(
        private readonly Database $db,
        private readonly AuthService $auth,
        private readonly AuthorizationService $authz,
        private readonly AuditService $audit,
        private readonly Clock $clock,
        private readonly TransactionRunner $tx,
        private readonly SettingsService $settings,
        private readonly InspectionService $inspections,
        private readonly DocumentNumberService $numbers,
        private readonly SheetSyncQueue $queue,
        private readonly IdempotencyService $idempotency,
        private readonly RequestContext $context,
    ) {
    }

    // ================================================================ rules

    /**
     * First enabled stage after $after (null = after submission), or QA_APPROVED.
     * Uses the report type configuration in force now.
     *
     * @param array<string, mixed> $report must contain the report_types requires_* columns
     */
    public function nextStatus(array $report, ?WorkflowStage $after): ReportStatus
    {
        $passed = $after === null;
        foreach (WorkflowStage::cases() as $stage) {
            if ($passed && (int) $report[$stage->configColumn()] === 1) {
                return $stage->pendingStatus();
            }
            if ($stage === $after) {
                $passed = true;
            }
        }

        return ReportStatus::QaApproved;
    }

    /**
     * What the user may do with the report now (drives the buttons; the
     * transition methods check everything again).
     *
     * @param array<string, mixed> $report
     * @param array<string, mixed> $user
     *
     * @return array{submit: bool, edit: bool, stage: WorkflowStage|null, sign: bool, signBlocked: string|null,
     *               cancel: bool, revise: bool, reviseBlocked: string|null, print: bool}
     */
    public function available(array $report, array $user): array
    {
        $status = ReportStatus::from($report['status']);
        $out    = [
            'submit' => false, 'edit' => false, 'stage' => null, 'sign' => false, 'signBlocked' => null,
            'cancel' => false, 'revise' => false, 'reviseBlocked' => null,
            'print'  => $this->authz->userCan($user, 'inspection.print'),
        ];

        if ($status->isEditable()) {
            $out['edit']   = $this->inspections->canEdit($report, $user);
            $out['submit'] = $out['edit'] && $this->authz->userCan($user, 'inspection.submit');
            $out['cancel'] = $this->canCancel($report, $user);
        }

        $stage = WorkflowStage::forPendingStatus($status);
        if ($stage !== null) {
            $out['stage'] = $stage;
            if ($this->authz->userCan($user, $stage->permission())) {
                $out['signBlocked'] = $this->segregationProblem($report, $stage, $user);
                $out['sign']        = $out['signBlocked'] === null;
            }
        }

        if ($status === ReportStatus::QaApproved && (int) $report['is_current'] === 1 && $this->authz->userCan($user, 'inspection.revise')) {
            $open = $this->openRevision((string) $report['report_no']);
            $out['revise']        = $open === null;
            $out['reviseBlocked'] = $open === null ? null : 'Revision ' . $open['revision_no'] . ' is already in progress.';
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $report
     * @param array<string, mixed> $user
     */
    public function canCancel(array $report, array $user): bool
    {
        if (! ReportStatus::from($report['status'])->isEditable()) {
            return false;
        }

        return $this->authz->userCan($user, 'inspection.cancel_any')
            || ($this->authz->userCan($user, 'inspection.cancel_own') && (int) $report['created_by'] === (int) $user['id']);
    }

    /**
     * Segregation of duties (docs/architecture/06-roles-permissions.md §6.4).
     * Applies to the Super Admin too. Returns the reason the user may not sign, or null.
     *
     * @param array<string, mixed> $report
     * @param array<string, mixed> $user
     */
    public function segregationProblem(array $report, WorkflowStage $stage, array $user): ?string
    {
        $uid = (int) $user['id'];
        if ((int) ($report['submitted_by'] ?? 0) === $uid) {
            return 'You submitted this report, so another person must sign it.';
        }
        $signatures = $this->db->table('inspection_approvals')->select('stage, action')
            ->where('report_id', $report['id'])->where('user_id', $uid)->whereIn('action', ['SUBMITTED', 'APPROVED'])
            ->get()->getResultArray();
        $distinct = $this->settings->bool('workflow.distinct_signers', true);
        foreach ($signatures as $signature) {
            if ($signature['action'] === 'SUBMITTED') {
                return 'You submitted this report, so another person must sign it.';
            }
            if ($distinct && $signature['stage'] !== $stage->approvalStage()) {
                return 'You already signed another stage of this report. A different person must sign this stage.';
            }
        }

        return null;
    }

    /**
     * Progress of the current workflow cycle for the detail page and the printout.
     *
     * @param array<string, mixed>       $report
     * @param list<array<string, mixed>> $approvals oldest first
     *
     * @return list<array{key: string, label: string, title: string, state: string, signature: array<string, mixed>|null}>
     */
    public function steps(array $report, array $approvals): array
    {
        $status     = ReportStatus::from($report['status']);
        $lastSubmit = null;
        foreach ($approvals as $i => $approval) {
            if ($approval['action'] === 'SUBMITTED') {
                $lastSubmit = $i;
            }
        }
        $cycle     = $lastSubmit === null ? [] : array_slice($approvals, $lastSubmit);
        $submitted = $lastSubmit !== null && ! $status->isEditable() && $status !== ReportStatus::Cancelled;
        $last      = $approvals === [] ? null : $approvals[array_key_last($approvals)];

        $steps = [[
            'key'       => 'SUBMISSION',
            'label'     => 'Submitted',
            'title'     => 'Operator',
            'state'     => $submitted ? 'done' : ($status->isEditable() ? 'current' : 'todo'),
            'signature' => $submitted ? $approvals[$lastSubmit] : null,
        ]];

        foreach (WorkflowStage::cases() as $stage) {
            $signed = null;
            foreach ($cycle as $approval) {
                if ($approval['stage'] === $stage->approvalStage() && $approval['action'] === 'APPROVED') {
                    $signed = $approval;
                }
            }
            if ((int) $report[$stage->configColumn()] !== 1 && $signed === null) {
                continue;
            }
            $state = 'todo';
            $sig   = null;
            if ($submitted && $signed !== null) {
                $state = 'done';
                $sig   = $signed;
            } elseif ($status === $stage->pendingStatus()) {
                $state = 'current';
            }
            if ($last !== null && $last['stage'] === $stage->approvalStage() && in_array($last['action'], ['RETURNED', 'REJECTED'], true)
                && in_array($status, [ReportStatus::Returned, ReportStatus::Rejected], true)) {
                $state = strtolower($last['action']);
                $sig   = $last;
            }
            $steps[] = ['key' => $stage->value, 'label' => $stage->label(), 'title' => $stage->signerTitle(), 'state' => $state, 'signature' => $sig];
        }

        return $steps;
    }

    /**
     * @return array<string, mixed>|null the revision of this report number that is still in progress
     */
    public function openRevision(string $reportNo): ?array
    {
        return $this->db->table('inspection_reports')->select('id, revision_no, status')
            ->where('report_no', $reportNo)->where('revision_no >', 0)->whereIn('status', self::OPEN_STATUSES)
            ->get(1)->getRowArray();
    }

    // ================================================================ transitions

    /**
     * Operator submission (DRAFT / RETURNED → first enabled stage).
     *
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    public function submit(int $reportId, string $seenStatus, ?string $idempotencyKey, array $user): array
    {
        return $this->idempotency->run((int) $user['id'], $idempotencyKey, 'submit', ['report' => $reportId, 'seen' => $seenStatus],
            function (Database $db) use ($reportId, $seenStatus, $user): array {
                $report = $this->lockReport($reportId);
                $this->assertSeen($report, $seenStatus);
                $from = ReportStatus::from($report['status']);
                if (! $from->isEditable()) {
                    throw new WorkflowException('This report has already been submitted.');
                }
                if (! $this->authz->userCan($user, 'inspection.submit') || ! $this->inspections->canEdit($report, $user)) {
                    throw new AuthorizationException('You may not submit this report.');
                }

                $totals   = $this->inspections->reevaluate($report);
                $problems = $this->inspections->submissionProblems($report);
                if ($problems !== []) {
                    $errors = [];
                    foreach ($problems as $i => $problem) {
                        $errors['problem-' . ($i + 1)] = $problem;
                    }

                    throw new ValidationException($errors, 'The report cannot be submitted yet. Complete the items below.');
                }

                $now = $this->clock->nowUtcString();
                if ($report['layout'] !== 'SHIFT_GRID') {
                    // Submitting is the operator's signature of the single inspection round.
                    $db->table('inspection_rounds')->where('report_id', $reportId)->where('status', 'OPEN')->update([
                        'status' => 'SIGNED', 'operator_user_id' => $user['id'], 'operator_signed_at' => $now,
                        'updated_at' => $now, 'updated_by' => $user['id'],
                    ]);
                }

                $reportNo = $report['report_no'];
                if ($reportNo === null) {
                    $type     = $db->table('report_types')->where('id', $report['report_type_id'])->get(1)->getRowArray();
                    $reportNo = $this->numbers->allocate($type, (string) $report['inspection_date']);
                }
                $to = $this->nextStatus($report, null);
                $db->table('inspection_reports')->where('id', $reportId)->update([
                    'report_no'    => $reportNo,
                    'status'       => $to->value,
                    'submitted_at' => $now,
                    'submitted_by' => $user['id'],
                    'lock_version' => (int) $report['lock_version'] + 1,
                    'updated_at'   => $now,
                    'updated_by'   => $user['id'],
                ]);
                $this->sign($reportId, 'SUBMISSION', 'SUBMITTED', $from, $to, $user, null, false);
                $this->queue->enqueueReport($reportId);

                $ref = $this->ref($reportNo, $report);
                $this->audit->log($from === ReportStatus::Returned ? 'RESUBMIT' : 'SUBMIT', 'inspection', $reportId, ['status' => $from->value], [
                    'status' => $to->value, 'report_no' => $reportNo, 'overall_result' => $totals['overall_result'], 'oos_count' => $totals['oos_count'],
                ], $ref);

                return [
                    'ok'        => true,
                    'report_id' => $reportId,
                    'report_no' => $reportNo,
                    'status'    => $to->value,
                    'message'   => "Report {$ref} submitted. Waiting for " . (WorkflowStage::forPendingStatus($to)?->label() ?? 'approval') . '.',
                ];
            });
    }

    /**
     * Verification / approval stage signature: approve, return or reject.
     *
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    public function act(int $reportId, string $action, string $remarks, string $password, string $seenStatus, ?string $idempotencyKey, array $user): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw ValidationException::single('action', 'Unknown action.');
        }
        $remarks = trim($remarks);
        if ($action !== 'approve' && $remarks === '') {
            throw ValidationException::single('remarks', $action === 'return' ? 'Explain what must be corrected.' : 'Give the reason for the rejection.');
        }
        if (mb_strlen($remarks) > 1000) {
            throw ValidationException::single('remarks', 'Remarks are limited to 1000 characters.');
        }
        $reauth = $this->settings->bool('workflow.reauth_on_sign', true);
        if ($reauth) {
            $this->auth->confirmPassword($password);
        }

        return $this->idempotency->run((int) $user['id'], $idempotencyKey, 'act', ['report' => $reportId, 'action' => $action, 'remarks' => $remarks, 'seen' => $seenStatus],
            function (Database $db) use ($reportId, $action, $remarks, $seenStatus, $reauth, $user): array {
                $pre = $db->table('inspection_reports')->select('report_no, revision_no')->where('id', $reportId)->get(1)->getRowArray()
                    ?? throw new NotFoundException('Inspection report not found.');
                if ((int) $pre['revision_no'] > 0) {
                    // A revision approval also changes the current approved row: lock both in id order.
                    $db->query('SELECT id FROM inspection_reports WHERE report_no = ? AND (id = ? OR is_current = 1) ORDER BY id FOR UPDATE', [$pre['report_no'], $reportId]);
                }
                $report = $this->lockReport($reportId);
                $this->assertSeen($report, $seenStatus);

                $from  = ReportStatus::from($report['status']);
                $stage = WorkflowStage::forPendingStatus($from) ?? throw new WorkflowException('This report is not waiting for a signature.');
                if (! $this->authz->userCan($user, $stage->permission())) {
                    throw new AuthorizationException('You may not sign the ' . $stage->label() . ' stage.');
                }
                $problem = $this->segregationProblem($report, $stage, $user);
                if ($problem !== null) {
                    throw new AuthorizationException($problem);
                }

                [$to, $signAction] = match ($action) {
                    'approve' => [$this->nextStatus($report, $stage), 'APPROVED'],
                    'return'  => [ReportStatus::Returned, 'RETURNED'],
                    'reject'  => [ReportStatus::Rejected, 'REJECTED'],
                };
                $now    = $this->clock->nowUtcString();
                $ref    = $this->ref((string) $report['report_no'], $report);
                $update = ['status' => $to->value, 'lock_version' => (int) $report['lock_version'] + 1, 'updated_at' => $now, 'updated_by' => $user['id']];
                if ($to === ReportStatus::QaApproved) {
                    $update['approved_at'] = $now;
                    $update['approved_by'] = $user['id'];
                }

                $superseded = null;
                if ($to === ReportStatus::QaApproved && (int) $report['revision_no'] > 0) {
                    $superseded = $db->table('inspection_reports')->where('report_no', $report['report_no'])
                        ->where('is_current', 1)->where('id !=', $reportId)->get(1)->getRowArray();
                    if ($superseded !== null) {
                        $db->table('inspection_reports')->where('id', $superseded['id'])->update([
                            'status' => ReportStatus::Superseded->value, 'is_current' => 0, 'updated_at' => $now, 'updated_by' => $user['id'],
                        ]);
                        $this->sign((int) $superseded['id'], 'REVISION', 'SUPERSEDED', ReportStatus::from($superseded['status']), ReportStatus::Superseded,
                            $user, 'Superseded by revision ' . $report['revision_no'] . '.', $reauth);
                    }
                    $update['is_current'] = 1;
                }

                $db->table('inspection_reports')->where('id', $reportId)->update($update);
                if ($to === ReportStatus::Returned && $report['layout'] !== 'SHIFT_GRID') {
                    // The originator corrects the values and signs again by resubmitting.
                    $db->table('inspection_rounds')->where('report_id', $reportId)->update([
                        'status' => 'OPEN', 'operator_user_id' => null, 'operator_signed_at' => null, 'updated_at' => $now, 'updated_by' => $user['id'],
                    ]);
                }
                $this->sign($reportId, $stage->approvalStage(), $signAction, $from, $to, $user, $remarks === '' ? null : $remarks, $reauth);

                $this->queue->enqueueReport($reportId);
                if ($to === ReportStatus::QaApproved || $to === ReportStatus::Rejected) {
                    $this->queue->enqueueObservations($reportId, (string) $report['inspection_date']);
                }
                if ($superseded !== null) {
                    $this->queue->enqueueReport((int) $superseded['id']);
                    $this->audit->log('SUPERSEDE', 'inspection', (int) $superseded['id'], ['status' => $superseded['status']],
                        ['status' => ReportStatus::Superseded->value, 'by_revision' => (int) $report['revision_no']], $this->ref((string) $superseded['report_no'], $superseded));
                }
                $this->audit->log(strtoupper($action), 'inspection', $reportId, ['status' => $from->value],
                    ['status' => $to->value, 'stage' => $stage->value, 'remarks' => $remarks === '' ? null : $remarks], $ref);

                $message = match (true) {
                    $to === ReportStatus::QaApproved => "Report {$ref} approved.",
                    $action === 'approve'            => "Report {$ref} verified. Waiting for " . (WorkflowStage::forPendingStatus($to)?->label() ?? 'approval') . '.',
                    $action === 'return'             => "Report {$ref} returned to the originator for correction.",
                    default                          => "Report {$ref} rejected.",
                };

                return ['ok' => true, 'report_id' => $reportId, 'status' => $to->value, 'message' => $message];
            });
    }

    /**
     * Cancels a draft or returned report (final). A numbered report keeps its number.
     *
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    public function cancel(int $reportId, string $reason, string $seenStatus, array $user): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::single('remarks', 'Give the reason for cancelling.');
        }
        if (mb_strlen($reason) > 1000) {
            throw ValidationException::single('remarks', 'The reason is limited to 1000 characters.');
        }

        return $this->tx->run(function (Database $db) use ($reportId, $reason, $seenStatus, $user): array {
            $report = $this->lockReport($reportId);
            $this->assertSeen($report, $seenStatus);
            $from = ReportStatus::from($report['status']);
            if (! $from->isEditable()) {
                throw new WorkflowException('Only a draft or a returned report can be cancelled.');
            }
            if (! $this->canCancel($report, $user)) {
                throw new AuthorizationException('You may not cancel this report.');
            }
            $now = $this->clock->nowUtcString();
            $db->table('inspection_reports')->where('id', $reportId)->update([
                'status' => ReportStatus::Cancelled->value, 'lock_version' => (int) $report['lock_version'] + 1, 'updated_at' => $now, 'updated_by' => $user['id'],
            ]);
            $this->sign($reportId, 'CANCELLATION', 'CANCELLED', $from, ReportStatus::Cancelled, $user, $reason, false);
            if ($report['report_no'] !== null) {
                $this->queue->enqueueReport($reportId);
            }
            $ref = $this->ref($report['report_no'], $report);
            $this->audit->log('CANCEL', 'inspection', $reportId, ['status' => $from->value], ['status' => ReportStatus::Cancelled->value, 'reason' => $reason], $ref);

            return ['ok' => true, 'report_id' => $reportId, 'status' => ReportStatus::Cancelled->value, 'message' => "Report {$ref} cancelled."];
        });
    }

    /**
     * Correction of an approved report: a new DRAFT revision with a copy of all
     * data. The original stays QA_APPROVED (and current) until the revision is approved.
     *
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    public function revise(int $reportId, string $reason, ?string $idempotencyKey, array $user): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::single('remarks', 'Give the reason for the correction.');
        }
        if (mb_strlen($reason) > 500) {
            throw ValidationException::single('remarks', 'The reason is limited to 500 characters.');
        }
        if (! $this->authz->userCan($user, 'inspection.revise')) {
            throw new AuthorizationException('You may not create revisions.');
        }

        return $this->idempotency->run((int) $user['id'], $idempotencyKey, 'revise', ['report' => $reportId, 'reason' => $reason],
            function (Database $db) use ($reportId, $reason, $user): array {
                $report = $this->lockReport($reportId);
                if ($report['status'] !== ReportStatus::QaApproved->value || (int) $report['is_current'] !== 1) {
                    throw new WorkflowException('Only the current approved version of a report can be corrected.');
                }
                $open = $this->openRevision((string) $report['report_no']);
                if ($open !== null) {
                    throw new WorkflowException('Revision ' . $open['revision_no'] . ' of this report is already in progress.');
                }
                $revisionNo = (int) $db->table('inspection_reports')->selectMax('revision_no', 'm')->where('report_no', $report['report_no'])->get()->getRow('m') + 1;
                if ($revisionNo > 99) {
                    throw new WorkflowException('This report has reached the maximum number of revisions.');
                }

                $now = $this->clock->nowUtcString();
                $db->table('inspection_reports')->insert([
                    'client_uuid'          => Uuid::v4(),
                    'report_no'            => $report['report_no'],
                    'revision_no'          => $revisionNo,
                    'parent_report_id'     => $reportId,
                    'revision_reason'      => $reason,
                    'is_current'           => 0,
                    'report_type_id'       => $report['report_type_id'],
                    'template_id'          => $report['template_id'],
                    'part_id'              => $report['part_id'],
                    'machine_id'           => $report['machine_id'],
                    'shift_id'             => $report['shift_id'],
                    'inspection_date'      => $report['inspection_date'],
                    'received_at'          => $report['received_at'],
                    'finish_at'            => $report['finish_at'],
                    'operator_employee_id' => $report['operator_employee_id'],
                    'setter_employee_id'   => $report['setter_employee_id'],
                    'status'               => ReportStatus::Draft->value,
                    'overall_result'       => $report['overall_result'],
                    'oos_count'            => $report['oos_count'],
                    'remarks'              => $report['remarks'],
                    'created_at'           => $now,
                    'created_by'           => $user['id'],
                    'updated_at'           => $now,
                    'updated_by'           => $user['id'],
                ]);
                $newId  = (int) $db->insertID();
                $single = $report['layout'] !== 'SHIFT_GRID';

                // Rounds: single-round reports are signed again by the reviser at submission;
                // In-Process rounds keep their signatures until reopened for correction.
                foreach ($db->table('inspection_rounds')->where('report_id', $reportId)->orderBy('round_no')->get()->getResultArray() as $round) {
                    $db->table('inspection_rounds')->insert([
                        'report_id'          => $newId,
                        'round_no'           => $round['round_no'],
                        'shift_id'           => $round['shift_id'],
                        'inspection_time'    => $round['inspection_time'],
                        'status'             => $single ? 'OPEN' : $round['status'],
                        'lot_status'         => $round['lot_status'],
                        'accepted_qty'       => $round['accepted_qty'],
                        'rejected_qty'       => $round['rejected_qty'],
                        'rework_qty'         => $round['rework_qty'],
                        'operator_user_id'   => $single ? null : $round['operator_user_id'],
                        'operator_signed_at' => $single ? null : $round['operator_signed_at'],
                        'qe_user_id'         => $single ? null : $round['qe_user_id'],
                        'qe_verified_at'     => $single ? null : $round['qe_verified_at'],
                        'remarks'            => $round['remarks'],
                        'created_at'         => $now,
                        'created_by'         => $user['id'],
                        'updated_at'         => $now,
                    ]);
                }
                $db->query(
                    'INSERT INTO inspection_observations (report_id, round_id, template_parameter_id, lsl, usl, gauge_id, gauge_cal_due_date,
                                                          gauge_cal_valid, result, remarks, created_at, created_by, updated_at)
                     SELECT ?, nr.id, o.template_parameter_id, o.lsl, o.usl, o.gauge_id, o.gauge_cal_due_date,
                            o.gauge_cal_valid, o.result, o.remarks, ?, ?, ?
                       FROM inspection_observations o
                       JOIN inspection_rounds orr ON orr.id = o.round_id
                       JOIN inspection_rounds nr ON nr.report_id = ? AND nr.round_no = orr.round_no
                      WHERE o.report_id = ?',
                    [$newId, $now, $user['id'], $now, $newId, $reportId],
                );
                $db->query(
                    'INSERT INTO inspection_readings (observation_id, reading_no, value_numeric, value_choice, value_text, value_date,
                                                      value_time, result, created_at, created_by, updated_at)
                     SELECT nobs.id, rd.reading_no, rd.value_numeric, rd.value_choice, rd.value_text, rd.value_date,
                            rd.value_time, rd.result, ?, ?, ?
                       FROM inspection_readings rd
                       JOIN inspection_observations oobs ON oobs.id = rd.observation_id
                       JOIN inspection_rounds orr ON orr.id = oobs.round_id
                       JOIN inspection_rounds nr ON nr.report_id = ? AND nr.round_no = orr.round_no
                       JOIN inspection_observations nobs ON nobs.round_id = nr.id AND nobs.template_parameter_id = oobs.template_parameter_id
                      WHERE oobs.report_id = ?',
                    [$now, $user['id'], $now, $newId, $reportId],
                );

                $current = ReportStatus::QaApproved;
                $this->sign($reportId, 'REVISION', 'REVISION_CREATED', $current, $current, $user, $reason, false);
                $ref = $this->ref((string) $report['report_no'], $report);
                $this->audit->log('REVISE', 'inspection', $reportId, null, ['revision_no' => $revisionNo, 'revision_id' => $newId, 'reason' => $reason], $ref);
                $this->audit->log('CREATE', 'inspection', $newId, null, ['revision_of' => $reportId, 'revision_no' => $revisionNo], $report['report_no'] . ' Rev ' . $revisionNo);

                return [
                    'ok'        => true,
                    'report_id' => $newId,
                    'status'    => ReportStatus::Draft->value,
                    'message'   => "Revision {$revisionNo} of {$report['report_no']} created. Correct the values and submit it for approval.",
                ];
            });
    }

    // ================================================================ inbox

    /**
     * Reports waiting for a stage the user may sign, oldest first.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $filters type, q
     *
     * @return list<array<string, mixed>>
     */
    public function inbox(array $user, array $filters, Paging $paging): array
    {
        $statuses = [];
        foreach (WorkflowStage::cases() as $stage) {
            if ($this->authz->userCan($user, $stage->permission())) {
                $statuses[] = $stage->pendingStatus()->value;
            }
        }
        if ($statuses === []) {
            return [];
        }

        $builder = $this->db->table('inspection_reports r')
            ->select('r.*, rt.code AS type_code, rt.name AS type_name, p.part_number, p.part_name, m.machine_code,
                      s.code AS shift_code, su.username AS submitted_by_name, se.full_name AS submitted_by_full')
            ->join('report_types rt', 'rt.id = r.report_type_id')
            ->join('parts p', 'p.id = r.part_id')
            ->join('machines m', 'm.id = r.machine_id')
            ->join('shifts s', 's.id = r.shift_id', 'left')
            ->join('users su', 'su.id = r.submitted_by', 'left')
            ->join('employees se', 'se.id = su.employee_id', 'left')
            ->whereIn('r.status', $statuses)
            ->where('r.submitted_by !=', $user['id']);
        if (! empty($filters['type'])) {
            $builder->where('r.report_type_id', (int) $filters['type']);
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $builder->groupStart()->like('r.report_no', $q)->orLike('p.part_number', $q)->orLike('m.machine_code', $q)->groupEnd();
        }
        $paging->total = (int) $builder->countAllResults(false);
        $rows          = $builder->orderBy('r.submitted_at')->limit($paging->perPage, $paging->offset())->get()->getResultArray();

        foreach ($rows as &$row) {
            $stage            = WorkflowStage::forPendingStatus(ReportStatus::from($row['status']));
            $row['blocked']   = $stage === null ? null : $this->segregationProblem($row, $stage, $user);
            $row['age_hours'] = $row['submitted_at'] === null ? 0
                : (int) floor(($this->clock->nowUtc()->getTimestamp() - strtotime($row['submitted_at'] . ' UTC')) / 3600);
        }

        return $rows;
    }

    // ================================================================ internals

    /**
     * @return array<string, mixed>
     */
    private function lockReport(int $reportId): array
    {
        $this->db->query('SELECT id FROM inspection_reports WHERE id = ? FOR UPDATE', [$reportId]);

        return $this->inspections->report($reportId);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function assertSeen(array $report, string $seenStatus): void
    {
        if ($seenStatus !== '' && $seenStatus !== $report['status']) {
            throw new ConcurrencyException('This report has changed since you opened it (it is now "'
                . ReportStatus::from($report['status'])->label() . '"). Reload the page to see the latest version.');
        }
    }

    /**
     * Appends one electronic signature (who, capacity, meaning, when, why).
     *
     * @param array<string, mixed> $user
     */
    private function sign(int $reportId, string $stage, string $action, ReportStatus $from, ReportStatus $to, array $user, ?string $remarks, bool $reauthenticated): void
    {
        $this->db->table('inspection_approvals')->insert([
            'report_id'       => $reportId,
            'stage'           => $stage,
            'action'          => $action,
            'from_status'     => $from->value,
            'to_status'       => $to->value,
            'user_id'         => $user['id'],
            'role_id'         => $user['role_id'],
            'remarks'         => $remarks,
            'reauthenticated' => $reauthenticated ? 1 : 0,
            'ip_address'      => mb_substr($this->context->ip(), 0, 45),
            'signed_at'       => $this->clock->nowUtcString(),
        ]);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function ref(?string $reportNo, array $report): string
    {
        if ($reportNo === null || $reportNo === '') {
            return ($report['type_code'] ?? 'Report') . ' draft #' . $report['id'];
        }

        return $reportNo . ((int) ($report['revision_no'] ?? 0) > 0 ? ' Rev ' . $report['revision_no'] : '');
    }
}
