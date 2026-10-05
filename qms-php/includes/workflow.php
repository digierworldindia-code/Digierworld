<?php
/**
 * Report workflow: Draft → Production → Quality → QA Approved, plus return,
 * reject, cancel and correction revisions.
 *
 * Every transition runs in one transaction: the report row is locked
 * (SELECT … FOR UPDATE), the status the user saw is compared (a stale page gets
 * 409), permission and segregation of duties are checked, and one append-only
 * signature row is written together with the audit entry and the Google Sheets
 * outbox job. Submit, approval and revision requests are idempotent.
 */
defined('QMS') || exit;

require_once QMS_ROOT . '/includes/inspections.php';
require_once QMS_ROOT . '/includes/numbering.php';
require_once QMS_ROOT . '/includes/idempotency.php';
require_once QMS_ROOT . '/includes/sheets.php';

const WF_ACTIONS       = ['approve', 'return', 'reject'];
const WF_OPEN_STATUSES = ['DRAFT', 'PENDING_PRODUCTION', 'PENDING_QUALITY', 'PENDING_QA', 'RETURNED'];
const WF_ACTION_LABELS = [
    'SUBMITTED' => ['bi-send-check', 'Submitted'], 'APPROVED' => ['bi-check-circle-fill', 'Signed'], 'RETURNED' => ['bi-arrow-return-left', 'Returned'],
    'REJECTED' => ['bi-x-octagon-fill', 'Rejected'], 'CANCELLED' => ['bi-x-circle', 'Cancelled'], 'REVISION_CREATED' => ['bi-layers', 'Revision created'],
    'SUPERSEDED' => ['bi-layers-half', 'Superseded'],
];
const WF_STAGE_LABELS = [
    'SUBMISSION' => 'Submission', 'PRODUCTION_VERIFICATION' => 'Production verification', 'QUALITY_VERIFICATION' => 'Quality verification',
    'QA_APPROVAL' => 'QA approval', 'REVISION' => 'Revision', 'CANCELLATION' => 'Cancellation',
];

// ================================================================ rules

/**
 * First enabled stage after $after (null = right after submission), or QA_APPROVED.
 * Uses the report type configuration in force now.
 */
function wf_next_status(array $report, ?string $after): string
{
    $passed = $after === null;
    foreach (WORKFLOW_STAGES as $key => $stage) {
        if ($passed && (int) $report[$stage['column']] === 1) {
            return $stage['pending'];
        }
        if ($key === $after) {
            $passed = true;
        }
    }

    return 'QA_APPROVED';
}

/** What the user may do with the report now (buttons; every action checks again). */
function wf_available(array $report, array $user): array
{
    $out = ['submit' => false, 'edit' => false, 'stage' => null, 'sign' => false, 'signBlocked' => null,
        'cancel' => false, 'revise' => false, 'reviseBlocked' => null, 'print' => user_can($user, 'inspection.print')];

    if (status_editable((string) $report['status'])) {
        $out['edit']   = insp_can_edit($report, $user);
        $out['submit'] = $out['edit'] && user_can($user, 'inspection.submit');
        $out['cancel'] = wf_can_cancel($report, $user);
    }
    $stage = stage_for_status((string) $report['status']);
    if ($stage !== null) {
        $out['stage'] = $stage;
        if (user_can($user, WORKFLOW_STAGES[$stage]['permission'])) {
            $out['signBlocked'] = wf_segregation_problem($report, $stage, $user);
            $out['sign']        = $out['signBlocked'] === null;
        }
    }
    if ($report['status'] === 'QA_APPROVED' && (int) $report['is_current'] === 1 && user_can($user, 'inspection.revise')) {
        $open                 = wf_open_revision((string) $report['report_no']);
        $out['revise']        = $open === null;
        $out['reviseBlocked'] = $open === null ? null : 'Revision ' . $open['revision_no'] . ' is already in progress.';
    }

    return $out;
}

function wf_can_cancel(array $report, array $user): bool
{
    if (! status_editable((string) $report['status'])) {
        return false;
    }

    return user_can($user, 'inspection.cancel_any')
        || (user_can($user, 'inspection.cancel_own') && (int) $report['created_by'] === (int) $user['id']);
}

/**
 * Segregation of duties (applies to the Super Admin too): the submitter never
 * signs a stage, and with "distinct signers" on, one person signs one stage only.
 * Returns the reason the user may not sign, or null.
 */
function wf_segregation_problem(array $report, string $stage, array $user): ?string
{
    $uid = (int) $user['id'];
    if ((int) ($report['submitted_by'] ?? 0) === $uid) {
        return 'You submitted this report, so another person must sign it.';
    }
    $signatures = db_all("SELECT stage, action FROM inspection_approvals WHERE report_id = ? AND user_id = ? AND action IN ('SUBMITTED', 'APPROVED')",
        [$report['id'], $uid]);
    $distinct = setting_bool('workflow.distinct_signers', true);
    foreach ($signatures as $signature) {
        if ($signature['action'] === 'SUBMITTED') {
            return 'You submitted this report, so another person must sign it.';
        }
        if ($distinct && $signature['stage'] !== WORKFLOW_STAGES[$stage]['approval_stage']) {
            return 'You already signed another stage of this report. A different person must sign this stage.';
        }
    }

    return null;
}

/**
 * Progress of the current workflow cycle (detail page and printout).
 *
 * @return list<array{key: string, label: string, title: string, state: string, signature: ?array}>
 */
function wf_steps(array $report, array $approvals): array
{
    $status     = (string) $report['status'];
    $lastSubmit = null;
    foreach ($approvals as $i => $approval) {
        if ($approval['action'] === 'SUBMITTED') {
            $lastSubmit = $i;
        }
    }
    $cycle     = $lastSubmit === null ? [] : array_slice($approvals, $lastSubmit);
    $submitted = $lastSubmit !== null && ! status_editable($status) && $status !== 'CANCELLED';
    $last      = $approvals === [] ? null : $approvals[array_key_last($approvals)];

    $steps = [[
        'key'       => 'SUBMISSION',
        'label'     => 'Submitted',
        'title'     => 'Operator',
        'state'     => $submitted ? 'done' : (status_editable($status) ? 'current' : 'todo'),
        'signature' => $submitted ? $approvals[$lastSubmit] : null,
    ]];
    foreach (WORKFLOW_STAGES as $key => $stage) {
        $signed = null;
        foreach ($cycle as $approval) {
            if ($approval['stage'] === $stage['approval_stage'] && $approval['action'] === 'APPROVED') {
                $signed = $approval;
            }
        }
        if ((int) $report[$stage['column']] !== 1 && $signed === null) {
            continue;
        }
        $state = 'todo';
        $sig   = null;
        if ($submitted && $signed !== null) {
            $state = 'done';
            $sig   = $signed;
        } elseif ($status === $stage['pending']) {
            $state = 'current';
        }
        if ($last !== null && $last['stage'] === $stage['approval_stage'] && in_array($last['action'], ['RETURNED', 'REJECTED'], true)
            && in_array($status, ['RETURNED', 'REJECTED'], true)) {
            $state = strtolower($last['action']);
            $sig   = $last;
        }
        $steps[] = ['key' => $key, 'label' => $stage['label'], 'title' => $stage['signer'], 'state' => $state, 'signature' => $sig];
    }

    return $steps;
}

/** The revision of a report number that is still in progress, or null. */
function wf_open_revision(string $reportNo): ?array
{
    return db_row('SELECT id, revision_no, status FROM inspection_reports WHERE report_no = ? AND revision_no > 0 AND status IN (' . db_in(WF_OPEN_STATUSES) . ') LIMIT 1',
        [$reportNo, ...WF_OPEN_STATUSES]);
}

// ================================================================ transitions

/** Operator submission: DRAFT / RETURNED → first enabled stage. */
function wf_submit(int $reportId, string $seenStatus, ?string $idempotencyKey, array $user): array
{
    return idempotent_run((int) $user['id'], $idempotencyKey, 'submit', ['report' => $reportId, 'seen' => $seenStatus],
        static function () use ($reportId, $seenStatus, $user): array {
            $report = wf_lock_report($reportId);
            wf_assert_seen($report, $seenStatus);
            $from = (string) $report['status'];
            if (! status_editable($from)) {
                fail('This report has already been submitted.', 409);
            }
            if (! user_can($user, 'inspection.submit') || ! insp_can_edit($report, $user)) {
                fail('You may not submit this report.', 403);
            }

            $totals   = insp_reevaluate($report);
            $problems = insp_submission_problems($report);
            if ($problems !== []) {
                $errors = [];
                foreach ($problems as $i => $problem) {
                    $errors['problem-' . ($i + 1)] = $problem;
                }
                throw new ValidationError($errors, 'The report cannot be submitted yet. Complete the items below.');
            }

            $now = now_str();
            if ($report['layout'] !== 'SHIFT_GRID') {
                // Submitting is the operator's signature of the single inspection round.
                db_exec("UPDATE inspection_rounds SET status = 'SIGNED', operator_user_id = ?, operator_signed_at = ?, updated_at = ?, updated_by = ?
                          WHERE report_id = ? AND status = 'OPEN'", [$user['id'], $now, $now, $user['id'], $reportId]);
            }
            $reportNo = $report['report_no'];
            if ($reportNo === null) {
                $reportNo = number_allocate(db_row('SELECT * FROM report_types WHERE id = ?', [$report['report_type_id']]), (string) $report['inspection_date']);
            }
            $to = wf_next_status($report, null);
            db_update('inspection_reports', ['report_no' => $reportNo, 'status' => $to, 'submitted_at' => $now, 'submitted_by' => $user['id'],
                'lock_version' => (int) $report['lock_version'] + 1, 'updated_at' => $now, 'updated_by' => $user['id']], 'id = ?', [$reportId]);
            wf_sign($reportId, 'SUBMISSION', 'SUBMITTED', $from, $to, $user, null, false);
            sheet_enqueue_report($reportId);

            $ref = wf_ref($reportNo, $report);
            audit_log($from === 'RETURNED' ? 'RESUBMIT' : 'SUBMIT', 'inspection', $reportId, ['status' => $from],
                ['status' => $to, 'report_no' => $reportNo, 'overall_result' => $totals['overall_result'], 'oos_count' => $totals['oos_count']], $ref);
            $next = stage_for_status($to);

            return ['ok' => true, 'report_id' => $reportId, 'report_no' => $reportNo, 'status' => $to,
                'message' => "Report {$ref} submitted. " . ($next !== null ? 'Waiting for ' . WORKFLOW_STAGES[$next]['label'] . '.' : 'It is approved.')];
        });
}

/** Stage signature: approve, return or reject. */
function wf_act(int $reportId, string $action, string $remarks, string $password, string $seenStatus, ?string $idempotencyKey, array $user): array
{
    if (! in_array($action, WF_ACTIONS, true)) {
        fail_field('action', 'Unknown action.');
    }
    $remarks = trim($remarks);
    if ($action !== 'approve' && $remarks === '') {
        fail_field('remarks', $action === 'return' ? 'Explain what must be corrected.' : 'Give the reason for the rejection.');
    }
    if (mb_strlen($remarks) > 1000) {
        fail_field('remarks', 'Remarks are limited to 1000 characters.');
    }
    $reauth = setting_bool('workflow.reauth_on_sign', true);
    if ($reauth) {
        auth_confirm_password($password);
    }

    return idempotent_run((int) $user['id'], $idempotencyKey, 'act', ['report' => $reportId, 'action' => $action, 'remarks' => $remarks, 'seen' => $seenStatus],
        static function () use ($reportId, $action, $remarks, $seenStatus, $reauth, $user): array {
            $pre = db_row('SELECT report_no, revision_no FROM inspection_reports WHERE id = ?', [$reportId]) ?? not_found('Inspection report not found.');
            if ((int) $pre['revision_no'] > 0) {
                // A revision approval also changes the current approved row: lock both in id order.
                db_query('SELECT id FROM inspection_reports WHERE report_no = ? AND (id = ? OR is_current = 1) ORDER BY id FOR UPDATE', [$pre['report_no'], $reportId]);
            }
            $report = wf_lock_report($reportId);
            wf_assert_seen($report, $seenStatus);

            $from  = (string) $report['status'];
            $stage = stage_for_status($from) ?? fail('This report is not waiting for a signature.', 409);
            $info  = WORKFLOW_STAGES[$stage];
            if (! user_can($user, $info['permission'])) {
                fail('You may not sign the ' . $info['label'] . ' stage.', 403);
            }
            $problem = wf_segregation_problem($report, $stage, $user);
            if ($problem !== null) {
                fail($problem, 403);
            }

            [$to, $signAction] = match ($action) {
                'approve' => [wf_next_status($report, $stage), 'APPROVED'],
                'return'  => ['RETURNED', 'RETURNED'],
                'reject'  => ['REJECTED', 'REJECTED'],
            };
            $now    = now_str();
            $ref    = wf_ref((string) $report['report_no'], $report);
            $update = ['status' => $to, 'lock_version' => (int) $report['lock_version'] + 1, 'updated_at' => $now, 'updated_by' => $user['id']];
            if ($to === 'QA_APPROVED') {
                $update['approved_at'] = $now;
                $update['approved_by'] = $user['id'];
            }

            $superseded = null;
            if ($to === 'QA_APPROVED' && (int) $report['revision_no'] > 0) {
                $superseded = db_row('SELECT * FROM inspection_reports WHERE report_no = ? AND is_current = 1 AND id <> ?', [$report['report_no'], $reportId]);
                if ($superseded !== null) {
                    db_update('inspection_reports', ['status' => 'SUPERSEDED', 'is_current' => 0, 'updated_at' => $now, 'updated_by' => $user['id']], 'id = ?', [$superseded['id']]);
                    wf_sign((int) $superseded['id'], 'REVISION', 'SUPERSEDED', (string) $superseded['status'], 'SUPERSEDED', $user,
                        'Superseded by revision ' . $report['revision_no'] . '.', $reauth);
                }
                $update['is_current'] = 1;
            }
            db_update('inspection_reports', $update, 'id = ?', [$reportId]);
            if ($to === 'RETURNED' && $report['layout'] !== 'SHIFT_GRID') {
                // The originator corrects the values and signs again by resubmitting.
                db_exec("UPDATE inspection_rounds SET status = 'OPEN', operator_user_id = NULL, operator_signed_at = NULL, updated_at = ?, updated_by = ? WHERE report_id = ?",
                    [$now, $user['id'], $reportId]);
            }
            wf_sign($reportId, $info['approval_stage'], $signAction, $from, $to, $user, $remarks === '' ? null : $remarks, $reauth);

            sheet_enqueue_report($reportId);
            if ($to === 'QA_APPROVED' || $to === 'REJECTED') {
                sheet_enqueue_observations($reportId, (string) $report['inspection_date']);
            }
            if ($superseded !== null) {
                sheet_enqueue_report((int) $superseded['id']);
                audit_log('SUPERSEDE', 'inspection', (int) $superseded['id'], ['status' => $superseded['status']],
                    ['status' => 'SUPERSEDED', 'by_revision' => (int) $report['revision_no']], wf_ref((string) $superseded['report_no'], $superseded));
            }
            audit_log(strtoupper($action), 'inspection', $reportId, ['status' => $from], ['status' => $to, 'stage' => $stage, 'remarks' => $remarks === '' ? null : $remarks], $ref);

            $next    = stage_for_status($to);
            $message = match (true) {
                $to === 'QA_APPROVED' => "Report {$ref} approved.",
                $action === 'approve' => "Report {$ref} verified. Waiting for " . ($next !== null ? WORKFLOW_STAGES[$next]['label'] : 'approval') . '.',
                $action === 'return'  => "Report {$ref} returned to the originator for correction.",
                default               => "Report {$ref} rejected.",
            };

            return ['ok' => true, 'report_id' => $reportId, 'status' => $to, 'message' => $message];
        });
}

/** Cancels a draft or returned report (final). A numbered report keeps its number. */
function wf_cancel(int $reportId, string $reason, string $seenStatus, array $user): array
{
    $reason = trim($reason);
    if ($reason === '') {
        fail_field('remarks', 'Give the reason for cancelling.');
    }
    if (mb_strlen($reason) > 1000) {
        fail_field('remarks', 'The reason is limited to 1000 characters.');
    }

    return db_transaction(static function () use ($reportId, $reason, $seenStatus, $user): array {
        $report = wf_lock_report($reportId);
        wf_assert_seen($report, $seenStatus);
        $from = (string) $report['status'];
        if (! status_editable($from)) {
            fail('Only a draft or a returned report can be cancelled.', 409);
        }
        if (! wf_can_cancel($report, $user)) {
            fail('You may not cancel this report.', 403);
        }
        $now = now_str();
        db_update('inspection_reports', ['status' => 'CANCELLED', 'lock_version' => (int) $report['lock_version'] + 1, 'updated_at' => $now, 'updated_by' => $user['id']],
            'id = ?', [$reportId]);
        wf_sign($reportId, 'CANCELLATION', 'CANCELLED', $from, 'CANCELLED', $user, $reason, false);
        if ($report['report_no'] !== null) {
            sheet_enqueue_report($reportId);
        }
        $ref = wf_ref($report['report_no'], $report);
        audit_log('CANCEL', 'inspection', $reportId, ['status' => $from], ['status' => 'CANCELLED', 'reason' => $reason], $ref);

        return ['ok' => true, 'report_id' => $reportId, 'status' => 'CANCELLED', 'message' => "Report {$ref} cancelled."];
    });
}

/**
 * Correction of an approved report: a new DRAFT revision with a copy of all data.
 * The original stays QA_APPROVED (and current) until the revision is approved.
 */
function wf_revise(int $reportId, string $reason, ?string $idempotencyKey, array $user): array
{
    $reason = trim($reason);
    if ($reason === '') {
        fail_field('remarks', 'Give the reason for the correction.');
    }
    if (mb_strlen($reason) > 500) {
        fail_field('remarks', 'The reason is limited to 500 characters.');
    }
    if (! user_can($user, 'inspection.revise')) {
        fail('You may not create revisions.', 403);
    }

    return idempotent_run((int) $user['id'], $idempotencyKey, 'revise', ['report' => $reportId, 'reason' => $reason],
        static function () use ($reportId, $reason, $user): array {
            $report = wf_lock_report($reportId);
            if ($report['status'] !== 'QA_APPROVED' || (int) $report['is_current'] !== 1) {
                fail('Only the current approved version of a report can be corrected.', 409);
            }
            $open = wf_open_revision((string) $report['report_no']);
            if ($open !== null) {
                fail('Revision ' . $open['revision_no'] . ' of this report is already in progress.', 409);
            }
            $revisionNo = (int) db_value('SELECT MAX(revision_no) FROM inspection_reports WHERE report_no = ?', [$report['report_no']]) + 1;
            if ($revisionNo > 99) {
                fail('This report has reached the maximum number of revisions.', 409);
            }

            $now   = now_str();
            $newId = db_insert('inspection_reports', [
                'client_uuid'          => uuid_v4(),
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
                'status'               => 'DRAFT',
                'overall_result'       => $report['overall_result'],
                'oos_count'            => $report['oos_count'],
                'remarks'              => $report['remarks'],
                'created_at'           => $now,
                'created_by'           => $user['id'],
                'updated_at'           => $now,
                'updated_by'           => $user['id'],
            ]);
            $single = $report['layout'] !== 'SHIFT_GRID';

            // Single-round reports are signed again by the reviser at submission;
            // In-Process rounds keep their signatures until reopened for correction.
            foreach (db_all('SELECT * FROM inspection_rounds WHERE report_id = ? ORDER BY round_no', [$reportId]) as $round) {
                db_insert('inspection_rounds', [
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
            db_exec('INSERT INTO inspection_observations (report_id, round_id, template_parameter_id, lsl, usl, gauge_id, gauge_cal_due_date,
                                                          gauge_cal_valid, result, remarks, created_at, created_by, updated_at)
                     SELECT ?, nr.id, o.template_parameter_id, o.lsl, o.usl, o.gauge_id, o.gauge_cal_due_date,
                            o.gauge_cal_valid, o.result, o.remarks, ?, ?, ?
                       FROM inspection_observations o
                       JOIN inspection_rounds orr ON orr.id = o.round_id
                       JOIN inspection_rounds nr ON nr.report_id = ? AND nr.round_no = orr.round_no
                      WHERE o.report_id = ?', [$newId, $now, $user['id'], $now, $newId, $reportId]);
            db_exec('INSERT INTO inspection_readings (observation_id, reading_no, value_numeric, value_choice, value_text, value_date,
                                                      value_time, result, created_at, created_by, updated_at)
                     SELECT nobs.id, rd.reading_no, rd.value_numeric, rd.value_choice, rd.value_text, rd.value_date,
                            rd.value_time, rd.result, ?, ?, ?
                       FROM inspection_readings rd
                       JOIN inspection_observations oobs ON oobs.id = rd.observation_id
                       JOIN inspection_rounds orr ON orr.id = oobs.round_id
                       JOIN inspection_rounds nr ON nr.report_id = ? AND nr.round_no = orr.round_no
                       JOIN inspection_observations nobs ON nobs.round_id = nr.id AND nobs.template_parameter_id = oobs.template_parameter_id
                      WHERE oobs.report_id = ?', [$now, $user['id'], $now, $newId, $reportId]);

            wf_sign($reportId, 'REVISION', 'REVISION_CREATED', 'QA_APPROVED', 'QA_APPROVED', $user, $reason, false);
            $ref = wf_ref((string) $report['report_no'], $report);
            audit_log('REVISE', 'inspection', $reportId, null, ['revision_no' => $revisionNo, 'revision_id' => $newId, 'reason' => $reason], $ref);
            audit_log('CREATE', 'inspection', $newId, null, ['revision_of' => $reportId, 'revision_no' => $revisionNo], $report['report_no'] . ' Rev ' . $revisionNo);

            return ['ok' => true, 'report_id' => $newId, 'status' => 'DRAFT',
                'message' => "Revision {$revisionNo} of {$report['report_no']} created. Correct the values and submit it for approval."];
        });
}

// ================================================================ approval inbox

/** Reports waiting for a stage the user may sign, oldest first (filters type, q). */
function wf_inbox(array $user, array $filters, array &$paging): array
{
    $statuses = [];
    foreach (WORKFLOW_STAGES as $stage) {
        if (user_can($user, $stage['permission'])) {
            $statuses[] = $stage['pending'];
        }
    }
    if ($statuses === []) {
        return [];
    }
    $where  = ['r.status IN (' . db_in($statuses) . ')', 'r.submitted_by <> ?'];
    $params = [...$statuses, $user['id']];
    if (ctype_digit((string) ($filters['type'] ?? '')) && (int) $filters['type'] > 0) {
        $where[]  = 'r.report_type_id = ?';
        $params[] = (int) $filters['type'];
    }
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = "(r.report_no LIKE ? ESCAPE '!' OR p.part_number LIKE ? ESCAPE '!' OR m.machine_code LIKE ? ESCAPE '!')";
        array_push($params, db_like($q), db_like($q), db_like($q));
    }
    $from = ' FROM inspection_reports r
              JOIN report_types rt ON rt.id = r.report_type_id
              JOIN parts p ON p.id = r.part_id
              JOIN machines m ON m.id = r.machine_id
              LEFT JOIN shifts s ON s.id = r.shift_id
              LEFT JOIN users su ON su.id = r.submitted_by
              LEFT JOIN employees se ON se.id = su.employee_id
             WHERE ' . implode(' AND ', $where);
    $paging['total'] = (int) db_value('SELECT COUNT(*)' . $from, $params);
    $rows = db_all('SELECT r.*, rt.code AS type_code, rt.name AS type_name, p.part_number, p.part_name, m.machine_code,
                           s.code AS shift_code, su.username AS submitted_by_name, se.full_name AS submitted_by_full' . $from
        . ' ORDER BY r.submitted_at, r.id LIMIT ? OFFSET ?', [...$params, $paging['per_page'], $paging['offset']]);

    foreach ($rows as &$row) {
        $stage            = stage_for_status((string) $row['status']);
        $row['blocked']   = $stage === null ? null : wf_segregation_problem($row, $stage, $user);
        $row['age_hours'] = $row['submitted_at'] === null ? 0 : (int) floor((now_utc()->getTimestamp() - strtotime($row['submitted_at'] . ' UTC')) / 3600);
    }

    return $rows;
}

// ================================================================ internals

function wf_lock_report(int $reportId): array
{
    db_query('SELECT id FROM inspection_reports WHERE id = ? FOR UPDATE', [$reportId]);

    return insp_report($reportId);
}

function wf_assert_seen(array $report, string $seenStatus): void
{
    if ($seenStatus !== '' && $seenStatus !== $report['status']) {
        fail('This report has changed since you opened it (it is now "' . status_label((string) $report['status']) . '"). Reload the page to see the latest version.', 409);
    }
}

/** Appends one electronic signature: who, in which role, what, when and why. */
function wf_sign(int $reportId, string $stage, string $action, string $from, string $to, array $user, ?string $remarks, bool $reauthenticated): void
{
    db_insert('inspection_approvals', [
        'report_id'       => $reportId,
        'stage'           => $stage,
        'action'          => $action,
        'from_status'     => $from,
        'to_status'       => $to,
        'user_id'         => $user['id'],
        'role_id'         => $user['role_id'],
        'remarks'         => $remarks,
        'reauthenticated' => $reauthenticated ? 1 : 0,
        'ip_address'      => mb_substr(client_ip(), 0, 45),
        'signed_at'       => now_str(),
    ]);
}

function wf_ref(?string $reportNo, array $report): string
{
    if ($reportNo === null || $reportNo === '') {
        return ($report['type_code'] ?? 'Report') . ' draft #' . $report['id'];
    }

    return $reportNo . ((int) ($report['revision_no'] ?? 0) > 0 ? ' Rev ' . $report['revision_no'] : '');
}
