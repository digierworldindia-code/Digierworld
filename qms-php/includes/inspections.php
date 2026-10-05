<?php
/**
 * Inspection entry: start a report, load it, autosave, In-Process rounds,
 * who may view / edit, and the checks before submission. Submission and
 * approvals are in includes/workflow.php.
 *
 * Every value is validated and evaluated here (includes/spec.php). LSL / USL
 * always come from the template snapshot stored on the observation, never
 * from the browser.
 */
defined('QMS') || exit;

require_once QMS_ROOT . '/includes/spec.php';
require_once QMS_ROOT . '/includes/templates.php';
require_once QMS_ROOT . '/includes/gauges.php';

const LOT_STATUSES = ['ACCEPTED' => 'Accepted', 'REJECTED' => 'Rejected', 'REWORK' => 'Rework', 'ON_HOLD' => 'On hold'];

// ================================================================ start

/** Report types, parts, shifts and allowed dates for the "New inspection" page. */
function insp_wizard_options(): array
{
    return [
        'reportTypes' => db_all("SELECT rt.*, (SELECT COUNT(*) FROM inspection_templates t WHERE t.report_type_id = rt.id AND t.status = 'PUBLISHED') AS template_count
                                   FROM report_types rt WHERE rt.is_active = 1 ORDER BY rt.id"),
        'parts'       => db_all('SELECT id, part_number, part_name FROM parts WHERE is_active = 1 ORDER BY part_number'),
        'shifts'      => shifts_active(),
        'current'     => production_current(),
        'dates'       => production_allowed_dates(),
    ];
}

/** Machines on which the part can be inspected for the report type (a published template applies). */
function insp_machine_options(int $reportTypeId, int $partId): array
{
    return array_map(static fn (array $m): array => [
        'id' => (int) $m['id'], 'code' => $m['machine_code'], 'name' => $m['machine_name'],
        'template' => $m['template_code'], 'ambiguous' => $m['ambiguous'],
    ], template_machines_for($reportTypeId, $partId));
}

/**
 * Creates a draft, or returns the existing one for the same tablet request
 * (client_uuid) or the open In-Process day sheet of part + machine + date.
 */
function insp_start(array $input, array $user): int
{
    $uuid = strtolower(trim((string) ($input['client_uuid'] ?? '')));
    if ($uuid === '') {
        $uuid = uuid_v4();
    } elseif (! is_uuid_v4($uuid)) {
        fail_field('client_uuid', 'Invalid request. Reload the page and try again.');
    }
    $existing = db_row('SELECT id, created_by FROM inspection_reports WHERE client_uuid = ?', [$uuid]);
    if ($existing !== null) {
        if ((int) $existing['created_by'] !== (int) $user['id']) {
            fail('This request belongs to another user.', 403);
        }

        return (int) $existing['id'];
    }

    $id      = static fn (string $key): int => ctype_digit((string) ($input[$key] ?? '')) ? (int) $input[$key] : 0;
    $errors  = [];
    $type    = db_row('SELECT * FROM report_types WHERE id = ? AND is_active = 1', [$id('report_type_id')]);
    $part    = db_row('SELECT * FROM parts WHERE id = ? AND is_active = 1', [$id('part_id')]);
    $machine = db_row('SELECT * FROM machines WHERE id = ? AND is_active = 1', [$id('machine_id')]);
    if ($type === null) {
        $errors['report_type_id'] = 'Choose a report type.';
    }
    if ($part === null) {
        $errors['part_id'] = 'Choose a part.';
    }
    if ($machine === null) {
        $errors['machine_id'] = 'Choose a machine.';
    }
    $date = (string) ($input['inspection_date'] ?? '');
    if (! in_array($date, production_allowed_dates(), true)) {
        $errors['inspection_date'] = 'Choose today\'s production date (back-dating is limited by the administrator).';
    }
    $shiftId = null;
    if ($type !== null && $type['header_shift'] !== 'HIDDEN') {
        $shiftId = $id('shift_id');
        if ($shiftId === 0 && $type['header_shift'] === 'REQUIRED') {
            $errors['shift_id'] = 'Choose the shift.';
        } elseif ($shiftId !== 0 && ! in_array($shiftId, array_map('intval', array_column(shifts_active(), 'id')), true)) {
            $errors['shift_id'] = 'Choose a valid shift.';
        }
        $shiftId = $shiftId === 0 ? null : $shiftId;
    }
    if ($errors !== []) {
        throw new ValidationError($errors);
    }

    $template = template_resolve((int) $type['id'], (int) $part['id'], (int) $machine['id']);
    if ($template === null) {
        fail_field('template', "No published {$type['name']} template applies to part {$part['part_number']} on machine {$machine['machine_code']}. Ask the QA Admin to map one.");
    }

    return db_transaction(static function () use ($uuid, $type, $part, $machine, $date, $shiftId, $template, $user): int {
        if ($type['layout'] === 'SHIFT_GRID') {
            // One day sheet per part + machine + date: serialise creation per machine, then reuse the open sheet.
            db_query('SELECT id FROM machines WHERE id = ? FOR UPDATE', [(int) $machine['id']]);
            $open = db_value("SELECT id FROM inspection_reports WHERE report_type_id = ? AND part_id = ? AND machine_id = ? AND inspection_date = ?
                                 AND revision_no = 0 AND status <> 'CANCELLED' LIMIT 1", [$type['id'], $part['id'], $machine['id'], $date]);
            if ($open !== null) {
                return (int) $open;
            }
        }

        $now      = now_str();
        $reportId = db_insert('inspection_reports', [
            'client_uuid'          => $uuid,
            'report_type_id'       => (int) $type['id'],
            'template_id'          => (int) $template['id'],
            'part_id'              => (int) $part['id'],
            'machine_id'           => (int) $machine['id'],
            'shift_id'             => $type['layout'] === 'SHIFT_GRID' ? null : $shiftId,
            'inspection_date'      => $date,
            'received_at'          => $type['header_timing'] !== 'HIDDEN' ? substr($now, 0, 16) . ':00' : null,
            'operator_employee_id' => $type['header_operator'] !== 'HIDDEN' ? $user['employee_id'] : null,
            'status'               => 'DRAFT',
            'overall_result'       => 'INCOMPLETE',
            'created_at'           => $now,
            'created_by'           => $user['id'],
            'updated_at'           => $now,
            'updated_by'           => $user['id'],
        ]);
        if ($type['layout'] !== 'SHIFT_GRID') {
            insp_create_round($reportId, null, null, $user);
        }
        insp_refresh_totals($reportId);
        audit_log('CREATE', 'inspection', $reportId, null, [
            'type' => $type['code'], 'template' => $template['template_code'] . ' v' . $template['version'],
            'part' => $part['part_number'], 'machine' => $machine['machine_code'], 'date' => $date,
        ], "{$type['code']} draft #{$reportId}");

        return $reportId;
    });
}

// ================================================================ load

/** Report header with the names needed to display it. */
function insp_report(int $reportId): array
{
    return db_row('SELECT r.*, rt.code AS type_code, rt.name AS type_name, rt.layout, rt.header_shift, rt.header_operator, rt.header_setter, rt.header_timing,
                          rt.requires_production_verification, rt.requires_quality_verification, rt.requires_qa_approval,
                          t.template_code, t.version AS template_version, t.name AS template_name, t.format_doc_no, t.format_rev_no,
                          t.format_made_date, t.format_rev_date, t.planned_rounds_per_shift,
                          p.part_number, p.part_name, p.drawing_number, p.drawing_revision,
                          m.machine_code, m.machine_name, m.pm_due_date,
                          s.code AS shift_code, s.name AS shift_name,
                          op.employee_code AS operator_code, op.full_name AS operator_name, op.skill_level AS operator_skill,
                          st.employee_code AS setter_code, st.full_name AS setter_name,
                          cu.username AS created_by_name, su.username AS submitted_by_name, au.username AS approved_by_name
                     FROM inspection_reports r
                     JOIN report_types rt ON rt.id = r.report_type_id
                     JOIN inspection_templates t ON t.id = r.template_id
                     JOIN parts p ON p.id = r.part_id
                     JOIN machines m ON m.id = r.machine_id
                     LEFT JOIN shifts s ON s.id = r.shift_id
                     LEFT JOIN employees op ON op.id = r.operator_employee_id
                     LEFT JOIN employees st ON st.id = r.setter_employee_id
                     LEFT JOIN users cu ON cu.id = r.created_by
                     LEFT JOIN users su ON su.id = r.submitted_by
                     LEFT JOIN users au ON au.id = r.approved_by
                    WHERE r.id = ?', [$reportId]) ?? not_found('Inspection report not found.');
}

/** Everything the entry, detail and print pages need (checks that the user may view it). */
function insp_load(int $reportId, array $user): array
{
    $report = insp_report($reportId);
    if (! insp_can_view($report, $user)) {
        audit_log('ACCESS_DENIED', 'security', $reportId, null, ['required' => 'inspection.view', 'path' => '/' . current_page()]);
        fail('You may not open this inspection report.', 403);
    }
    $structure  = template_structure((int) $report['template_id']);
    $gaugeTypes = [];
    foreach ($structure as $section) {
        foreach ($section['parameters'] as $param) {
            if ($param['gauge_type_id'] !== null) {
                $gaugeTypes[(int) $param['gauge_type_id']] = true;
            }
        }
    }
    $gaugeOptions = [];
    foreach (array_keys($gaugeTypes) as $gaugeTypeId) {
        $gaugeOptions[$gaugeTypeId] = gauge_options_for_type($gaugeTypeId, (string) $report['inspection_date']);
    }

    return [
        'report'       => $report,
        'structure'    => $structure,
        'rounds'       => insp_rounds($reportId),
        'gaugeOptions' => $gaugeOptions,
        'approvals'    => insp_approvals($reportId),
        'shifts'       => shifts_active(),
        'employees'    => db_all('SELECT id, employee_code, full_name, skill_level FROM employees WHERE is_active = 1 ORDER BY full_name'),
        'canEdit'      => insp_can_edit($report, $user),
        'revisions'    => $report['report_no'] === null ? [] : db_all('SELECT id, revision_no, status, is_current FROM inspection_reports WHERE report_no = ? ORDER BY revision_no', [$report['report_no']]),
    ];
}

/** Rounds with observations (keyed by template parameter) and readings (keyed by reading number). */
function insp_rounds(int $reportId): array
{
    $rounds = db_all('SELECT ro.*, s.code AS shift_code, s.sort_order AS shift_sort, ou.username AS operator_username, oe.full_name AS operator_name,
                             qu.username AS qe_username, qe.full_name AS qe_name, cu.username AS created_by_name
                        FROM inspection_rounds ro
                        LEFT JOIN shifts s ON s.id = ro.shift_id
                        LEFT JOIN users ou ON ou.id = ro.operator_user_id
                        LEFT JOIN employees oe ON oe.id = ou.employee_id
                        LEFT JOIN users qu ON qu.id = ro.qe_user_id
                        LEFT JOIN employees qe ON qe.id = qu.employee_id
                        LEFT JOIN users cu ON cu.id = ro.created_by
                       WHERE ro.report_id = ?
                       ORDER BY s.sort_order, ro.inspection_time, ro.round_no', [$reportId]);
    if ($rounds === []) {
        return [];
    }
    $observations = db_all('SELECT o.*, g.gauge_code FROM inspection_observations o LEFT JOIN gauges g ON g.id = o.gauge_id WHERE o.report_id = ?', [$reportId]);
    $readings     = db_all('SELECT rd.* FROM inspection_readings rd JOIN inspection_observations o ON o.id = rd.observation_id WHERE o.report_id = ?', [$reportId]);

    $readingsByObs = [];
    foreach ($readings as $reading) {
        $readingsByObs[$reading['observation_id']][(int) $reading['reading_no']] = $reading;
    }
    $obsByRound = [];
    foreach ($observations as $obs) {
        $obs['readings'] = $readingsByObs[$obs['id']] ?? [];
        $obsByRound[$obs['round_id']][(int) $obs['template_parameter_id']] = $obs;
    }
    foreach ($rounds as &$round) {
        $round['observations'] = $obsByRound[$round['id']] ?? [];
    }

    return $rounds;
}

/** Signatures (inspection_approvals), oldest first. */
function insp_approvals(int $reportId): array
{
    return db_all('SELECT a.*, u.username, e.full_name, e.employee_code, e.designation, r.name AS role_name
                     FROM inspection_approvals a
                     JOIN users u ON u.id = a.user_id
                     LEFT JOIN employees e ON e.id = u.employee_id
                     JOIN roles r ON r.id = a.role_id
                    WHERE a.report_id = ?
                    ORDER BY a.signed_at, a.id', [$reportId]);
}

/** "SCA-2026-10-000001 Rev 1" or "Setup Change Approval (draft)". */
function insp_title(array $report): string
{
    if ($report['report_no'] !== null) {
        return $report['report_no'] . ((int) $report['revision_no'] > 0 ? ' Rev ' . $report['revision_no'] : '');
    }

    return $report['type_name'] . ' (draft)';
}

/** Short reference for audit rows and messages. */
function insp_ref(array $report): string
{
    if (($report['report_no'] ?? null) === null || $report['report_no'] === '') {
        return ($report['type_code'] ?? 'Report') . ' draft #' . $report['id'];
    }

    return $report['report_no'] . ((int) ($report['revision_no'] ?? 0) > 0 ? ' Rev ' . $report['revision_no'] : '');
}

// ================================================================ who may do what

function insp_can_view(array $report, array $user): bool
{
    if (user_can($user, 'inspection.view_all')) {
        return true;
    }
    $uid = (int) $user['id'];
    if (user_can($user, 'inspection.view_own')
        && ((int) $report['created_by'] === $uid || (int) ($report['submitted_by'] ?? 0) === $uid
            || (int) db_value('SELECT COUNT(*) FROM inspection_rounds WHERE report_id = ? AND (operator_user_id = ? OR created_by = ?)', [$report['id'], $uid, $uid]) > 0)) {
        return true;
    }
    // Approvers see what waits for them.
    $stage = stage_for_status((string) $report['status']);
    if ($stage !== null && user_can($user, WORKFLOW_STAGES[$stage]['permission'])) {
        return true;
    }

    // The open In-Process day sheet is shared by the operators of all shifts.
    return $report['layout'] === 'SHIFT_GRID' && $report['status'] === 'DRAFT' && user_can($user, 'inspection.create');
}

function insp_can_edit(array $report, array $user): bool
{
    if (! user_can($user, 'inspection.create')) {
        return false;
    }
    $uid = (int) $user['id'];

    return match ($report['status']) {
        'DRAFT'    => $report['layout'] === 'SHIFT_GRID' || (int) $report['created_by'] === $uid,
        'RETURNED' => (int) $report['created_by'] === $uid || (int) ($report['submitted_by'] ?? 0) === $uid,
        default    => false,
    };
}

function insp_can_edit_round(array $report, array $round, array $user): bool
{
    if (! insp_can_edit($report, $user) || $round['status'] !== 'OPEN') {
        return false;
    }

    return $report['layout'] !== 'SHIFT_GRID'
        || (int) $round['created_by'] === (int) $user['id']
        || (int) ($round['operator_user_id'] ?? 0) === (int) $user['id']
        || $report['status'] === 'RETURNED';
}

// ================================================================ autosave

/**
 * Saves changed cells, gauges, remarks, round details and header in one transaction.
 * Invalid cells are reported back (errors) while the valid ones are saved.
 */
function insp_save_draft(int $reportId, array $payload, array $user): array
{
    return db_transaction(static function () use ($reportId, $payload, $user): array {
        db_query('SELECT id FROM inspection_reports WHERE id = ? FOR UPDATE', [$reportId]);
        $report = insp_report($reportId);
        if (! status_editable((string) $report['status'])) {
            fail('This report has been submitted and can no longer be changed.', 423);
        }
        if (! insp_can_edit($report, $user)) {
            fail('You may not edit this report.', 403);
        }

        $changes = [];
        $touched = [];
        $errors  = [];
        $params  = insp_template_params((int) $report['template_id']);
        $rounds  = [];
        foreach (db_all('SELECT * FROM inspection_rounds WHERE report_id = ?', [$reportId]) as $round) {
            $rounds[(int) $round['id']] = $round;
        }

        if (isset($payload['header']) && is_array($payload['header'])) {
            $changes['header'] = insp_save_header($report, $payload['header'], (int) ($payload['lock_version'] ?? -1), $user);
            $report            = insp_report($reportId);
        }

        foreach ((array) ($payload['observations'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $obs = insp_observation_for($reportId, (int) ($item['observation_id'] ?? 0));
            insp_assert_round_editable($report, $rounds[(int) $obs['round_id']] ?? null, $user);
            $param  = $params[(int) $obs['template_parameter_id']];
            $update = [];
            if (array_key_exists('gauge_id', $item)) {
                $update += insp_gauge_fields($param, $item['gauge_id'], (string) $report['inspection_date'], (int) $obs['id'], $errors);
            }
            if (array_key_exists('remarks', $item)) {
                $remarks = trim(is_scalar($item['remarks']) ? (string) $item['remarks'] : '');
                if (mb_strlen($remarks) > 500) {
                    $errors['obs-' . $obs['id']] = 'Remarks are limited to 500 characters.';
                    continue;
                }
                $update['remarks'] = $remarks === '' ? null : $remarks;
            }
            if ($update !== []) {
                db_update('inspection_observations', $update + ['updated_at' => now_str(), 'updated_by' => $user['id']], 'id = ?', [$obs['id']]);
                $changes['observations'][] = ['observation' => (int) $obs['id'], 'parameter' => $param['name']] + $update;
            }
        }

        foreach ((array) ($payload['cells'] ?? []) as $cell) {
            if (! is_array($cell)) {
                continue;
            }
            $obs = insp_observation_for($reportId, (int) ($cell['observation_id'] ?? 0));
            insp_assert_round_editable($report, $rounds[(int) $obs['round_id']] ?? null, $user);
            $param = $params[(int) $obs['template_parameter_id']];
            $no    = (int) ($cell['reading_no'] ?? 0);
            if ($no < 1 || $no > (int) $param['observation_count']) {
                $errors["cell-{$obs['id']}-{$no}"] = 'Unknown observation number.';
                continue;
            }
            try {
                $raw   = isset($cell['value']) && is_scalar($cell['value']) ? (string) $cell['value'] : null;
                $value = spec_evaluate(['lsl' => $obs['lsl'], 'usl' => $obs['usl']] + $param, $raw, (string) $report['inspection_date']);
            } catch (ValidationError $e) {
                $errors["cell-{$obs['id']}-{$no}"] = $param['name'] . ': ' . $e->getMessage();
                continue;
            }
            $old                      = insp_write_reading((int) $obs['id'], $no, $value, $user);
            $touched[(int) $obs['id']] = true;
            $changes['cells'][]       = ['parameter' => $param['name'], 'round' => (int) $obs['round_id'], 'reading' => $no, 'old' => $old, 'new' => insp_display_value($value)];
        }

        foreach ((array) ($payload['rounds'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $round = $rounds[(int) ($item['round_id'] ?? 0)] ?? not_found('Inspection round not found.');
            insp_assert_round_editable($report, $round, $user);
            $update = insp_round_fields($item, $errors, (int) $round['id']);
            if ($update !== []) {
                db_update('inspection_rounds', $update + ['updated_at' => now_str(), 'updated_by' => $user['id']], 'id = ?', [$round['id']]);
                $changes['rounds'][] = ['round' => (int) $round['round_no']] + $update;
            }
        }

        foreach (array_keys($touched) as $obsId) {
            insp_refresh_observation($obsId, $params);
        }
        $totals = insp_refresh_totals($reportId);
        if ($changes !== []) {
            audit_log('SAVE_DRAFT', 'inspection', $reportId, null, $changes, insp_ref($report));
        }

        return [
            'ok'             => $errors === [],
            'errors'         => $errors,
            'lock_version'   => (int) db_value('SELECT lock_version FROM inspection_reports WHERE id = ?', [$reportId]),
            'overall_result' => $totals['overall_result'],
            'oos_count'      => $totals['oos_count'],
            'observations'   => insp_observation_states($reportId),
            'problems'       => insp_submission_problems(insp_report($reportId)),
            'saved_at'       => plant_now()->format('H:i'),
        ];
    });
}

// ================================================================ In-Process rounds

function insp_add_round(int $reportId, int $shiftId, string $time, array $user): int
{
    return db_transaction(static function () use ($reportId, $shiftId, $time, $user): int {
        db_query('SELECT id FROM inspection_reports WHERE id = ? FOR UPDATE', [$reportId]);
        $report = insp_report($reportId);
        if ($report['layout'] !== 'SHIFT_GRID') {
            fail_field('round', 'Only In-Process reports have inspection times.');
        }
        if (! insp_can_edit($report, $user)) {
            fail('You may not add inspections to this report.', 403);
        }
        if (! in_array($shiftId, array_map('intval', array_column(shifts_active(), 'id')), true)) {
            fail_field('shift_id', 'Choose a valid shift.');
        }
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            fail_field('inspection_time', 'Enter the inspection time as HH:MM.');
        }
        $roundId = insp_create_round($reportId, $shiftId, $time . ':00', $user);
        insp_refresh_totals($reportId);
        audit_log('ADD_ROUND', 'inspection', $reportId, null, ['shift_id' => $shiftId, 'time' => $time], insp_ref($report));

        return $roundId;
    });
}

/** Operator signature of an In-Process round ("Inspected by operator"). */
function insp_sign_round(int $reportId, int $roundId, array $user): void
{
    db_transaction(static function () use ($reportId, $roundId, $user): void {
        db_query('SELECT id FROM inspection_reports WHERE id = ? FOR UPDATE', [$reportId]);
        $report = insp_report($reportId);
        $round  = insp_round_for($reportId, $roundId);
        insp_assert_round_editable($report, $round, $user);
        $problems = insp_round_problems($report, $round);
        if ($problems !== []) {
            throw new ValidationError(['round' => implode(' ', $problems)], 'This inspection cannot be signed yet: ' . implode(' ', $problems));
        }
        $now = now_str();
        db_update('inspection_rounds', ['status' => 'SIGNED', 'operator_user_id' => $user['id'], 'operator_signed_at' => $now, 'updated_at' => $now, 'updated_by' => $user['id']],
            'id = ?', [$roundId]);
        audit_log('SIGN_ROUND', 'inspection', $reportId, ['round' => (int) $round['round_no'], 'status' => 'OPEN'], ['round' => (int) $round['round_no'], 'status' => 'SIGNED'], insp_ref($report));
    });
}

/** Quality engineer signature of an In-Process round ("Inspected by QE"). */
function insp_verify_round(int $reportId, int $roundId, string $password, array $user): void
{
    if (setting_bool('workflow.reauth_on_sign', true)) {
        auth_confirm_password($password);
    }
    db_transaction(static function () use ($reportId, $roundId, $user): void {
        db_query('SELECT id FROM inspection_reports WHERE id = ? FOR UPDATE', [$reportId]);
        $report = insp_report($reportId);
        $round  = insp_round_for($reportId, $roundId);
        if (! status_editable((string) $report['status'])) {
            fail('This report has been submitted.', 423);
        }
        if ($round['status'] !== 'SIGNED') {
            fail_field('round', 'Only an inspection signed by the operator can be verified.');
        }
        if ((int) $round['operator_user_id'] === (int) $user['id']) {
            fail('The quality engineer must be a different person from the operator who signed.', 403);
        }
        $now = now_str();
        db_update('inspection_rounds', ['status' => 'VERIFIED', 'qe_user_id' => $user['id'], 'qe_verified_at' => $now, 'updated_at' => $now, 'updated_by' => $user['id']],
            'id = ?', [$roundId]);
        audit_log('VERIFY_ROUND', 'inspection', $reportId, ['round' => (int) $round['round_no'], 'status' => 'SIGNED'], ['round' => (int) $round['round_no'], 'status' => 'VERIFIED'], insp_ref($report));
    });
}

function insp_reopen_round(int $reportId, int $roundId, string $reason, array $user): void
{
    $reason = trim($reason);
    if ($reason === '') {
        fail_field('remarks', 'Give the reason for reopening.');
    }
    db_transaction(static function () use ($reportId, $roundId, $reason, $user): void {
        db_query('SELECT id FROM inspection_reports WHERE id = ? FOR UPDATE', [$reportId]);
        $report = insp_report($reportId);
        $round  = insp_round_for($reportId, $roundId);
        if (! status_editable((string) $report['status'])) {
            fail('This report has been submitted.', 423);
        }
        if ($round['status'] === 'OPEN') {
            return;
        }
        db_update('inspection_rounds', ['status' => 'OPEN', 'qe_user_id' => null, 'qe_verified_at' => null, 'updated_at' => now_str(), 'updated_by' => $user['id']],
            'id = ?', [$roundId]);
        audit_log('REOPEN_ROUND', 'inspection', $reportId, ['round' => (int) $round['round_no'], 'status' => $round['status']],
            ['round' => (int) $round['round_no'], 'status' => 'OPEN', 'reason' => mb_substr($reason, 0, 500)], insp_ref($report));
    });
}

function insp_delete_round(int $reportId, int $roundId, array $user): void
{
    db_transaction(static function () use ($reportId, $roundId, $user): void {
        db_query('SELECT id FROM inspection_reports WHERE id = ? FOR UPDATE', [$reportId]);
        $report = insp_report($reportId);
        $round  = insp_round_for($reportId, $roundId);
        insp_assert_round_editable($report, $round, $user);
        $filled = (int) db_value('SELECT COUNT(*) FROM inspection_readings rd JOIN inspection_observations o ON o.id = rd.observation_id
                                    WHERE o.round_id = ? AND rd.result IS NOT NULL', [$roundId]);
        if ($filled > 0) {
            fail_field('round', 'This inspection already has readings. Clear them first.');
        }
        db_exec('DELETE FROM inspection_rounds WHERE id = ?', [$roundId]);
        insp_refresh_totals($reportId);
        audit_log('DELETE_ROUND', 'inspection', $reportId, ['round' => (int) $round['round_no']], null, insp_ref($report));
    });
}

// ================================================================ checks before submission

/** Everything that prevents submission, in plain language. */
function insp_submission_problems(array $report): array
{
    $problems = [];
    if ($report['header_shift'] === 'REQUIRED' && $report['shift_id'] === null) {
        $problems[] = 'Choose the shift.';
    }
    if ($report['header_operator'] === 'REQUIRED' && $report['operator_employee_id'] === null) {
        $problems[] = 'Choose the operator.';
    }
    if ($report['header_setter'] === 'REQUIRED' && $report['setter_employee_id'] === null) {
        $problems[] = 'Choose the setter.';
    }
    if ($report['header_timing'] === 'REQUIRED' && ($report['received_at'] === null || $report['finish_at'] === null)) {
        $problems[] = 'Enter the received time and the finish time.';
    }
    $rounds = db_all('SELECT * FROM inspection_rounds WHERE report_id = ? ORDER BY round_no', [$report['id']]);
    if ($rounds === []) {
        $problems[] = 'Add at least one inspection.';
    }
    foreach ($rounds as $round) {
        if ($report['layout'] === 'SHIFT_GRID' && $round['status'] === 'OPEN') {
            $problems[] = 'Inspection #' . $round['round_no'] . ' (' . substr((string) $round['inspection_time'], 0, 5) . ') is not signed by the operator.';
        }
        foreach (insp_round_problems($report, $round) as $problem) {
            $problems[] = $problem;
        }
    }

    return array_values(array_unique($problems));
}

/** Missing mandatory readings, missing or unusable gauges in one round. */
function insp_round_problems(array $report, array $round): array
{
    $params   = insp_template_params((int) $report['template_id']);
    $block    = setting_bool('gauge.block_expired_on_submit', true);
    $problems = [];
    $prefix   = $report['layout'] === 'SHIFT_GRID' ? 'Inspection #' . $round['round_no'] . ': ' : '';
    $rows     = db_all('SELECT o.*, g.gauge_code, g.status AS gauge_status FROM inspection_observations o LEFT JOIN gauges g ON g.id = o.gauge_id
                         WHERE o.round_id = ?', [$round['id']]);
    foreach ($rows as $obs) {
        $param = $params[(int) $obs['template_parameter_id']];
        if ($obs['result'] === 'INCOMPLETE') {
            $problems[] = $prefix . $param['name'] . ' is missing readings.';
        }
        if ((int) $param['gauge_required'] === 1 && $obs['gauge_id'] === null) {
            $problems[] = $prefix . $param['name'] . ' needs the Gauge ID.';
        }
        if ($obs['gauge_id'] !== null && (int) $obs['gauge_cal_valid'] === 0 && $block) {
            $problems[] = $prefix . 'Gauge ' . $obs['gauge_code'] . ' (' . $param['name'] . ') is not usable on the inspection date (calibration expired or gauge not active).';
        }
    }

    return $problems;
}

/**
 * Re-evaluates every stored reading against the specification snapshot of its
 * observation (inside the submit transaction): the stored verdicts are always
 * recomputed by the server from stored values.
 */
function insp_reevaluate(array $report): array
{
    $params   = insp_template_params((int) $report['template_id']);
    $readings = db_all('SELECT rd.*, o.lsl, o.usl, o.template_parameter_id FROM inspection_readings rd
                          JOIN inspection_observations o ON o.id = rd.observation_id WHERE o.report_id = ?', [$report['id']]);
    $corrected = 0;
    foreach ($readings as $reading) {
        $param  = $params[(int) $reading['template_parameter_id']];
        $result = spec_result_for_stored($param, $reading, $reading['lsl'], $reading['usl'], (string) $report['inspection_date']);
        if ($result === null && $reading['result'] !== null) {
            throw new ValidationError(['submit' => $param['name'] . ': stored value does not match the parameter type.'],
                $param['name'] . ': a stored value is inconsistent. Re-enter it and submit again.');
        }
        if ($result !== $reading['result']) {
            db_update('inspection_readings', ['result' => $result], 'id = ?', [$reading['id']]);
            $corrected++;
        }
    }
    foreach (db_column('SELECT id FROM inspection_observations WHERE report_id = ?', [$report['id']]) as $obsId) {
        insp_refresh_observation((int) $obsId, $params);
    }
    if ($corrected > 0) {
        log_message('warning', "Inspection {$report['id']}: {$corrected} stored reading verdicts were corrected on submission.");
    }

    return insp_refresh_totals((int) $report['id']) + ['corrected' => $corrected];
}

// ================================================================ internals

/** Parameters of a template version keyed by id (section order, then parameter order). */
function insp_template_params(int $templateId): array
{
    static $cache = [];
    if (! isset($cache[$templateId])) {
        $rows = db_all('SELECT tp.*, s.sort_order AS section_sort FROM template_parameters tp JOIN template_sections s ON s.id = tp.section_id
                         WHERE s.template_id = ? ORDER BY s.sort_order, s.id, tp.sort_order, tp.id', [$templateId]);
        $cache[$templateId] = array_column($rows, null, 'id');
    }

    return $cache[$templateId];
}

/** Inserts a round with one observation per template parameter (pre-fills applied). */
function insp_create_round(int $reportId, ?int $shiftId, ?string $time, array $user): int
{
    $report  = insp_report($reportId);
    $now     = now_str();
    $next    = (int) db_value('SELECT COALESCE(MAX(round_no), 0) FROM inspection_rounds WHERE report_id = ?', [$reportId]) + 1;
    $roundId = db_insert('inspection_rounds', ['report_id' => $reportId, 'round_no' => $next, 'shift_id' => $shiftId, 'inspection_time' => $time,
        'status' => 'OPEN', 'created_at' => $now, 'created_by' => $user['id'], 'updated_at' => $now]);

    $params = insp_template_params((int) $report['template_id']);
    foreach ($params as $param) {
        $obsId = db_insert('inspection_observations', [
            'report_id'             => $reportId,
            'round_id'              => $roundId,
            'template_parameter_id' => $param['id'],
            'lsl'                   => $param['lsl'],
            'usl'                   => $param['usl'],
            'result'                => (int) $param['is_mandatory'] === 1 ? 'INCOMPLETE' : 'NOT_APPLICABLE',
            'created_at'            => $now,
            'created_by'            => $user['id'],
            'updated_at'            => $now,
        ]);
        $prefill = match ($param['prefill_source']) {
            'OPERATOR_SKILL_LEVEL' => $report['operator_skill'] !== null ? (string) $report['operator_skill'] : null,
            'MACHINE_PM_DUE_DATE'  => $report['pm_due_date'],
            default                => null,
        };
        if ($prefill !== null) {
            insp_write_reading($obsId, 1, spec_evaluate($param, $prefill, (string) $report['inspection_date']), $user);
            insp_refresh_observation($obsId, $params);
        }
    }

    return $roundId;
}

/** Inserts or updates one reading; returns the previous value for the audit trail. */
function insp_write_reading(int $observationId, int $readingNo, array $value, array $user): ?string
{
    $now      = now_str();
    $existing = db_row('SELECT * FROM inspection_readings WHERE observation_id = ? AND reading_no = ?', [$observationId, $readingNo]);
    if ($existing === null) {
        if ($value['result'] === null) {
            return null;
        }
        db_insert('inspection_readings', $value + ['observation_id' => $observationId, 'reading_no' => $readingNo, 'created_at' => $now,
            'created_by' => $user['id'], 'updated_at' => $now]);

        return null;
    }
    db_update('inspection_readings', $value + ['updated_at' => $now, 'updated_by' => $user['id']], 'id = ?', [$existing['id']]);

    return insp_display_value($existing);
}

function insp_display_value(array $value): ?string
{
    foreach (['value_numeric', 'value_choice', 'value_text', 'value_date', 'value_time'] as $key) {
        if (($value[$key] ?? null) !== null) {
            return (string) $value[$key];
        }
    }

    return null;
}

function insp_refresh_observation(int $observationId, array $params): void
{
    $obs     = db_row('SELECT * FROM inspection_observations WHERE id = ?', [$observationId]);
    $param   = $params[(int) $obs['template_parameter_id']];
    $results = array_fill(1, (int) $param['observation_count'], null);
    foreach (db_all('SELECT reading_no, result FROM inspection_readings WHERE observation_id = ?', [$observationId]) as $r) {
        $results[(int) $r['reading_no']] = $r['result'];
    }
    $result = spec_aggregate($results, (int) $param['observation_count'], (int) $param['is_mandatory'] === 1);
    if ($result !== $obs['result']) {
        db_update('inspection_observations', ['result' => $result], 'id = ?', [$observationId]);
    }
}

/** Recomputes overall_result and oos_count of a report. */
function insp_refresh_totals(int $reportId): array
{
    $results = db_column('SELECT result FROM inspection_observations WHERE report_id = ?', [$reportId]);
    $overall = $results === [] ? 'INCOMPLETE' : spec_overall($results);
    $oos     = (int) db_value("SELECT COUNT(*) FROM inspection_readings rd JOIN inspection_observations o ON o.id = rd.observation_id
                                WHERE o.report_id = ? AND rd.result = 'FAIL'", [$reportId]);
    db_update('inspection_reports', ['overall_result' => $overall, 'oos_count' => $oos], 'id = ?', [$reportId]);

    return ['overall_result' => $overall, 'oos_count' => $oos];
}

/** @return array<int, array{result: string, readings: array<int, ?string>}> */
function insp_observation_states(int $reportId): array
{
    $out = [];
    foreach (db_all('SELECT id, result FROM inspection_observations WHERE report_id = ?', [$reportId]) as $obs) {
        $out[(int) $obs['id']] = ['result' => $obs['result'], 'readings' => []];
    }
    foreach (db_all('SELECT rd.observation_id, rd.reading_no, rd.result FROM inspection_readings rd JOIN inspection_observations o ON o.id = rd.observation_id
                      WHERE o.report_id = ?', [$reportId]) as $r) {
        $out[(int) $r['observation_id']]['readings'][(int) $r['reading_no']] = $r['result'];
    }

    return $out;
}

function insp_observation_for(int $reportId, int $observationId): array
{
    return db_row('SELECT * FROM inspection_observations WHERE id = ? AND report_id = ?', [$observationId, $reportId])
        ?? not_found('Observation not found in this report.');
}

function insp_round_for(int $reportId, int $roundId): array
{
    return db_row('SELECT * FROM inspection_rounds WHERE id = ? AND report_id = ?', [$roundId, $reportId])
        ?? not_found('Inspection round not found.');
}

function insp_assert_round_editable(array $report, ?array $round, array $user): void
{
    if ($round === null) {
        not_found('Inspection round not found.');
    }
    if ($round['status'] !== 'OPEN') {
        fail('This inspection is signed. Ask a QA Admin to reopen it to change values.', 423);
    }
    if (! insp_can_edit_round($report, $round, $user)) {
        fail('This inspection was started by another operator.', 403);
    }
}

/** Gauge chosen for an observation, with its calibration state on the inspection date. */
function insp_gauge_fields(array $param, mixed $gaugeId, string $date, int $obsId, array &$errors): array
{
    if ($gaugeId === null || $gaugeId === '' || ! is_scalar($gaugeId) || (int) $gaugeId === 0) {
        return ['gauge_id' => null, 'gauge_cal_due_date' => null, 'gauge_cal_valid' => null];
    }
    $gauge = db_row('SELECT g.*, t.requires_calibration FROM gauges g JOIN gauge_types t ON t.id = g.gauge_type_id WHERE g.id = ?', [(int) $gaugeId]);
    if ($gauge === null || ($param['gauge_type_id'] !== null && (int) $gauge['gauge_type_id'] !== (int) $param['gauge_type_id'])) {
        $errors['obs-' . $obsId] = $param['name'] . ': choose a gauge of the required type.';

        return [];
    }
    $availability = gauge_availability($gauge, $date);

    return ['gauge_id' => (int) $gauge['id'], 'gauge_cal_due_date' => $gauge['calibration_due_date'], 'gauge_cal_valid' => $availability['usable'] ? 1 : 0];
}

/** Round fields from the In-Process grid (time, lot status, quantities, remarks). */
function insp_round_fields(array $item, array &$errors, int $roundId): array
{
    $update = [];
    $text   = static fn (string $key): string => trim(is_scalar($item[$key] ?? null) ? (string) $item[$key] : '');
    if (array_key_exists('inspection_time', $item)) {
        $time = $text('inspection_time');
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $errors['round-' . $roundId] = 'Enter the inspection time as HH:MM.';
        } else {
            $update['inspection_time'] = $time . ':00';
        }
    }
    if (array_key_exists('lot_status', $item)) {
        $update['lot_status'] = isset(LOT_STATUSES[$text('lot_status')]) ? $text('lot_status') : null;
    }
    foreach (['accepted_qty', 'rejected_qty', 'rework_qty'] as $key) {
        if (array_key_exists($key, $item)) {
            $qty = $text($key);
            if ($qty === '') {
                $update[$key] = null;
            } elseif (! ctype_digit($qty) || strlen($qty) > 9) {
                $errors['round-' . $roundId] = 'Quantities must be whole numbers.';
            } else {
                $update[$key] = (int) $qty;
            }
        }
    }
    if (array_key_exists('remarks', $item)) {
        $remarks           = $text('remarks');
        $update['remarks'] = $remarks === '' ? null : mb_substr($remarks, 0, 500);
    }

    return $update;
}

/** Header changes, protected by optimistic locking (lock_version). */
function insp_save_header(array $report, array $input, int $lockVersion, array $user): array
{
    if ($lockVersion !== (int) $report['lock_version']) {
        fail('Someone else changed the report header. Reload to see their changes.', 409);
    }
    $errors = [];
    $update = [];
    $shifts = array_column(shifts_active(), null, 'id');
    $int    = static fn (mixed $v): int => is_scalar($v) && ctype_digit((string) $v) ? (int) $v : 0;

    if ($report['header_shift'] !== 'HIDDEN' && array_key_exists('shift_id', $input)) {
        $shiftId = $int($input['shift_id']);
        if ($shiftId !== 0 && ! isset($shifts[$shiftId])) {
            $errors['shift_id'] = 'Choose a valid shift.';
        } else {
            $update['shift_id'] = $shiftId === 0 ? null : $shiftId;
        }
    }
    foreach (['operator' => 'operator_employee_id', 'setter' => 'setter_employee_id'] as $kind => $column) {
        if ($report['header_' . $kind] !== 'HIDDEN' && array_key_exists($column, $input)) {
            $id = $int($input[$column]);
            if ($id !== 0 && (int) db_value('SELECT COUNT(*) FROM employees WHERE id = ? AND is_active = 1', [$id]) === 0) {
                $errors[$column] = 'Choose an active employee.';
            } else {
                $update[$column] = $id === 0 ? null : $id;
            }
        }
    }
    if ($report['header_timing'] !== 'HIDDEN') {
        $shift = $shifts[(int) ($update['shift_id'] ?? $report['shift_id'] ?? 0)] ?? null;
        foreach (['received_at', 'finish_at'] as $column) {
            if (! array_key_exists($column, $input)) {
                continue;
            }
            $time = trim(is_scalar($input[$column]) ? (string) $input[$column] : '');
            if ($time === '') {
                $update[$column] = null;
            } elseif (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                $errors[$column] = 'Enter the time as HH:MM.';
            } else {
                $update[$column] = production_to_utc((string) $report['inspection_date'], $time, $shift);
            }
        }
        $received = array_key_exists('received_at', $update) ? $update['received_at'] : $report['received_at'];
        $finish   = array_key_exists('finish_at', $update) ? $update['finish_at'] : $report['finish_at'];
        if ($received !== null && $finish !== null && $finish < $received) {
            $errors['finish_at'] = 'The finish time must be after the received time.';
        }
    }
    if (array_key_exists('remarks', $input)) {
        $remarks           = trim(is_scalar($input['remarks']) ? (string) $input['remarks'] : '');
        $update['remarks'] = $remarks === '' ? null : mb_substr($remarks, 0, 1000);
    }
    if ($errors !== []) {
        throw new ValidationError($errors);
    }
    if ($update === []) {
        return [];
    }

    $before = array_intersect_key($report, $update);
    db_update('inspection_reports', $update + ['lock_version' => (int) $report['lock_version'] + 1, 'updated_at' => now_str(), 'updated_by' => $user['id']],
        'id = ?', [$report['id']]);

    // A newly chosen operator pre-fills empty skill-level readings.
    if (isset($update['operator_employee_id'])) {
        insp_apply_operator_prefill((int) $report['id'], (int) $report['template_id'], $user);
    }

    return ['before' => $before, 'after' => $update];
}

function insp_apply_operator_prefill(int $reportId, int $templateId, array $user): void
{
    $report = insp_report($reportId);
    if ($report['operator_skill'] === null) {
        return;
    }
    $params = insp_template_params($templateId);
    foreach ($params as $param) {
        if ($param['prefill_source'] !== 'OPERATOR_SKILL_LEVEL') {
            continue;
        }
        foreach (db_all('SELECT * FROM inspection_observations WHERE report_id = ? AND template_parameter_id = ?', [$reportId, $param['id']]) as $obs) {
            $filled = (int) db_value('SELECT COUNT(*) FROM inspection_readings WHERE observation_id = ? AND reading_no = 1 AND result IS NOT NULL', [$obs['id']]);
            if ($filled === 0) {
                $value = spec_evaluate(['lsl' => $obs['lsl'], 'usl' => $obs['usl']] + $param, (string) $report['operator_skill'], (string) $report['inspection_date']);
                insp_write_reading((int) $obs['id'], 1, $value, $user);
                insp_refresh_observation((int) $obs['id'], $params);
            }
        }
    }
}

// ================================================================ lists

const INSP_LIST_SELECT = 'SELECT r.id, r.report_no, r.revision_no, r.is_current, r.status, r.overall_result, r.oos_count, r.inspection_date,
           r.created_at, r.created_by, r.submitted_at, r.submitted_by, r.updated_at,
           rt.code AS type_code, rt.name AS type_name, rt.layout,
           p.part_number, p.part_name, m.machine_code, m.machine_name, s.code AS shift_code,
           op.full_name AS operator_name, cu.username AS created_by_name,
           (SELECT COUNT(*) FROM inspection_rounds ro WHERE ro.report_id = r.id) AS round_count';

const INSP_LIST_FROM = ' FROM inspection_reports r
      JOIN report_types rt ON rt.id = r.report_type_id
      JOIN parts p ON p.id = r.part_id
      JOIN machines m ON m.id = r.machine_id
      LEFT JOIN shifts s ON s.id = r.shift_id
      LEFT JOIN employees op ON op.id = r.operator_employee_id
      LEFT JOIN users cu ON cu.id = r.created_by';

/**
 * Report search (filters from, to, type, part, machine, operator, shift, status, result, q, superseded).
 * Users with only inspection.view_own see the reports they created, submitted or inspected.
 */
function insp_search(array $filters, array &$paging, array $user): array
{
    $where  = [];
    $params = [];
    if (! user_can($user, 'inspection.view_all')) {
        $where[] = '(r.created_by = ? OR r.submitted_by = ? OR r.id IN (SELECT report_id FROM inspection_rounds WHERE operator_user_id = ? OR created_by = ?))';
        array_push($params, $user['id'], $user['id'], $user['id'], $user['id']);
    }
    foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
        $date = (string) ($filters[$key] ?? '');
        if ($date !== '' && validate_date($date)) {
            $where[]  = 'r.inspection_date ' . $op . ' ?';
            $params[] = $date;
        }
    }
    foreach (['type' => 'r.report_type_id', 'part' => 'r.part_id', 'machine' => 'r.machine_id', 'operator' => 'r.operator_employee_id'] as $key => $column) {
        if (ctype_digit((string) ($filters[$key] ?? '')) && (int) $filters[$key] > 0) {
            $where[]  = $column . ' = ?';
            $params[] = (int) $filters[$key];
        }
    }
    if (ctype_digit((string) ($filters['shift'] ?? '')) && (int) $filters['shift'] > 0) {
        $where[] = '(r.shift_id = ? OR r.id IN (SELECT report_id FROM inspection_rounds WHERE shift_id = ?))';
        array_push($params, (int) $filters['shift'], (int) $filters['shift']);
    }
    $status = (string) ($filters['status'] ?? '');
    if ($status === 'PENDING') {
        $where[] = 'r.status IN (' . db_in(PENDING_STATUSES) . ')';
        array_push($params, ...PENDING_STATUSES);
    } elseif (isset(REPORT_STATUSES[$status])) {
        $where[]  = 'r.status = ?';
        $params[] = $status;
    } elseif (empty($filters['superseded'])) {
        $where[] = "r.status <> 'SUPERSEDED'";
    }
    if (in_array($filters['result'] ?? '', ['PASS', 'FAIL', 'INCOMPLETE'], true)) {
        $where[]  = 'r.overall_result = ?';
        $params[] = $filters['result'];
    }
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = "(r.report_no LIKE ? ESCAPE '!' OR p.part_number LIKE ? ESCAPE '!' OR p.part_name LIKE ? ESCAPE '!' OR m.machine_code LIKE ? ESCAPE '!')";
        array_push($params, db_like($q), db_like($q), db_like($q), db_like($q));
    }
    $sqlWhere        = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $paging['total'] = (int) db_value('SELECT COUNT(*)' . INSP_LIST_FROM . $sqlWhere, $params);

    return db_all(INSP_LIST_SELECT . INSP_LIST_FROM . $sqlWhere . ' ORDER BY r.inspection_date DESC, r.id DESC LIMIT ? OFFSET ?',
        [...$params, $paging['per_page'], $paging['offset']]);
}

/** Lists for the operator home page. */
function insp_home_lists(array $user): array
{
    $uid   = (int) $user['id'];
    $dates = production_allowed_dates();

    return [
        'returned' => db_all(INSP_LIST_SELECT . INSP_LIST_FROM . " WHERE r.status = 'RETURNED' AND (r.created_by = ? OR r.submitted_by = ?)
                              ORDER BY r.updated_at DESC LIMIT 20", [$uid, $uid]),
        'drafts'   => db_all(INSP_LIST_SELECT . INSP_LIST_FROM . " WHERE r.status = 'DRAFT' AND r.created_by = ? AND rt.layout <> 'SHIFT_GRID'
                              ORDER BY r.updated_at DESC LIMIT 20", [$uid]),
        'sheets'   => db_all(INSP_LIST_SELECT . INSP_LIST_FROM . " WHERE r.status = 'DRAFT' AND rt.layout = 'SHIFT_GRID' AND r.inspection_date >= ?
                              ORDER BY r.inspection_date DESC, m.machine_code LIMIT 30", [end($dates)]),
        'recent'   => db_all(INSP_LIST_SELECT . INSP_LIST_FROM . " WHERE r.submitted_by = ? AND r.status <> 'DRAFT'
                              ORDER BY r.submitted_at DESC LIMIT 10", [$uid]),
    ];
}
