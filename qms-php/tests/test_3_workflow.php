<?php
/**
 * Database tests: masters, gauges, templates, inspections, workflow, integrity triggers.
 */
defined('QMS') || exit;

require_once QMS_ROOT . '/includes/masters.php';
require_once QMS_ROOT . '/includes/workflow.php';

/** Starts an inspection for the demo part / machine and returns its id. */
function t_start(string $typeCode, array $user, string $machine = 'CNC-01'): int
{
    $type = db_row('SELECT * FROM report_types WHERE code = ?', [$typeCode]);

    return insp_start([
        'client_uuid' => uuid_v4(), 'report_type_id' => $type['id'], 'part_id' => db_value("SELECT id FROM parts WHERE part_number = 'BF-1001'"),
        'machine_id' => db_value('SELECT id FROM machines WHERE machine_code = ?', [$machine]), 'inspection_date' => production_current()['date'],
        'shift_id' => db_value("SELECT id FROM shifts WHERE code = 'A'"),
    ], $user);
}

/** Fills every observation of a report with a passing value (and a usable gauge). */
function t_fill(int $reportId, array $user, array $override = []): array
{
    $report = insp_report($reportId);
    $params = insp_template_params((int) $report['template_id']);
    $cells  = [];
    $obsOut = [];
    foreach (db_all('SELECT o.* FROM inspection_observations o JOIN inspection_rounds r ON r.id = o.round_id WHERE o.report_id = ? AND r.status = ?', [$reportId, 'OPEN']) as $obs) {
        $param = $params[(int) $obs['template_parameter_id']];
        $value = $override[$param['name']] ?? match ($param['observation_type']) {
            'NUMERIC', 'PERCENTAGE' => $obs['lsl'] !== null ? qms_decimal($obs['lsl'], (int) $param['decimal_places']) : '1',
            'OK_NOT_OK', 'VISUAL'   => 'OK',
            'GO_NO_GO'              => 'GO',
            'DATE'                  => '2099-12-31',
            'TIME'                  => '08:00',
            default                 => 'ok',
        };
        for ($n = 1; $n <= (int) $param['observation_count']; $n++) {
            $cells[] = ['observation_id' => $obs['id'], 'reading_no' => $n, 'value' => $value];
        }
        if ($param['gauge_type_id'] !== null) {
            $gauge = db_value("SELECT g.id FROM gauges g WHERE g.gauge_type_id = ? AND g.status = 'ACTIVE' AND (g.calibration_due_date IS NULL OR g.calibration_due_date >= ?) ORDER BY g.id LIMIT 1",
                [$param['gauge_type_id'], $report['inspection_date']]);
            $obsOut[] = ['observation_id' => $obs['id'], 'gauge_id' => $gauge];
        }
    }
    $header = [];
    foreach (['operator' => 'operator_employee_id', 'setter' => 'setter_employee_id'] as $kind => $column) {
        if ($report['header_' . $kind] !== 'HIDDEN') {
            $header[$column] = (string) db_value("SELECT id FROM employees WHERE employee_code = 'E1001'");
        }
    }
    $result = insp_save_draft($reportId, ['lock_version' => (int) $report['lock_version'], 'header' => $header, 'cells' => $cells, 'observations' => $obsOut], $user);
    // Finish time = received time (independent of the hour the tests run at).
    db_exec('UPDATE inspection_reports SET finish_at = received_at WHERE id = ? AND received_at IS NOT NULL', [$reportId]);

    return $result;
}

function test_master_codes_are_unique_and_immutable(): void
{
    $admin = t_login('admin');
    $id    = master_create('machines', ['machine_code' => 'tst-01', 'machine_name' => 'Test lathe'], $admin);
    t_same('TST-01', db_value('SELECT machine_code FROM machines WHERE id = ?', [$id]), 'codes are upper-cased');
    t_throws(static fn () => master_create('machines', ['machine_code' => 'TST-01', 'machine_name' => 'Again'], $admin), ValidationError::class, 422, 'already exists');
    master_update('machines', $id, ['machine_code' => 'HACK', 'machine_name' => 'Renamed'], $admin);
    t_same('TST-01', db_value('SELECT machine_code FROM machines WHERE id = ?', [$id]), 'code unchanged');
    t_same(false, master_toggle('machines', $id, $admin), 'retired');
    t_same('RETIRE', db_value("SELECT action FROM audit_logs WHERE module = 'machines' ORDER BY id DESC LIMIT 1"));
    t_throws(static fn () => master_definition('nope'), QmsError::class, 404);
}

function test_gauge_calibration_rules(): void
{
    $qa    = t_login('qa');
    $type  = (int) db_value("SELECT id FROM gauge_types WHERE requires_calibration = 1 ORDER BY id LIMIT 1");
    $today = production_current()['date'];
    $id    = gauge_create(['gauge_code' => 'tg-1', 'gauge_name' => 'Test gauge', 'gauge_type_id' => (string) $type], $qa);
    t_same('NOT_CALIBRATED', gauge_availability(gauge_find($id), $today)['state']);
    $past = (new DateTimeImmutable($today))->modify('-30 days')->format('Y-m-d');
    $soon = (new DateTimeImmutable($today))->modify('+3 days')->format('Y-m-d');
    gauge_record_calibration($id, ['calibration_date' => $past, 'due_date' => $soon, 'result' => 'ACCEPTED'], null, $qa);
    t_same('DUE_SOON', gauge_availability(gauge_find($id), $today)['state']);
    t_same(false, gauge_availability(gauge_find($id), (new DateTimeImmutable($soon))->modify('+1 day')->format('Y-m-d'))['usable'], 'expired after the due date');
    gauge_record_calibration($id, ['calibration_date' => $today, 'due_date' => '2099-01-01', 'result' => 'REJECTED'], null, $qa);
    t_same('ON_HOLD', gauge_find($id)['status'], 'a rejected calibration puts the gauge on hold');
    t_throws(static fn () => gauge_record_calibration($id, ['calibration_date' => '2099-01-01', 'due_date' => '2099-02-01', 'result' => 'ACCEPTED'], null, $qa),
        ValidationError::class, 422, 'future');
    t_throws(static fn () => db_exec('DELETE FROM gauge_calibrations WHERE gauge_id = ?', [$id]), PDOException::class);
}

function test_template_lifecycle_and_resolver(): void
{
    $qa    = t_login('qa');
    $ppi   = (int) db_value("SELECT id FROM report_types WHERE code = 'PPI'");
    $part  = (int) db_value("SELECT id FROM parts WHERE part_number = 'BF-1001'");
    $cnc1  = (int) db_value("SELECT id FROM machines WHERE machine_code = 'CNC-01'");
    $base  = template_resolve($ppi, $part, $cnc1);
    t_ok($base !== null, 'demo PPI template applies');

    $id  = template_create(['report_type_id' => (string) $ppi, 'template_code' => 'ppi-cnc1', 'name' => 'PPI CNC-01', 'format_doc_no' => 'QA/F/02',
        'format_rev_no' => '01', 'format_made_date' => '2026-01-01'], $qa);
    t_throws(static fn () => template_publish($id, $qa), ValidationError::class, 422, 'at least one section');
    $sec = template_section_add($id, 'Dimensions', '', $qa);
    $lib = (int) db_value('SELECT id FROM inspection_parameters ORDER BY id LIMIT 1');
    t_throws(static fn () => template_param_save($id, ['section_id' => (string) $sec, 'parameter_id' => (string) $lib, 'observation_type' => 'NUMERIC',
        'decimal_places' => '2', 'lsl' => '6.60', 'usl' => '6.40', 'observation_count' => '1', 'prefill_source' => 'NONE'], $qa), ValidationError::class, 422, 'USL must be greater');
    template_param_save($id, ['section_id' => (string) $sec, 'parameter_id' => (string) $lib, 'observation_type' => 'NUMERIC', 'decimal_places' => '2',
        'lsl' => '6.40', 'usl' => '6.60', 'observation_count' => '2', 'is_mandatory' => '1', 'prefill_source' => 'NONE'], $qa);
    template_save_mapping($id, [(string) $part], [(string) $cnc1], $qa);
    template_publish($id, $qa);
    t_same('PPI-CNC1', template_resolve($ppi, $part, $cnc1)['template_code'], 'part + machine beats part only');
    t_same($base['template_code'], template_resolve($ppi, $part, (int) db_value("SELECT id FROM machines WHERE machine_code = 'CNC-02'"))['template_code']);

    // Published versions are locked by the application and by the database.
    t_throws(static fn () => template_section_add($id, 'More', '', $qa), QmsError::class, 423);
    t_throws(static fn () => db_exec('UPDATE template_parameters SET usl = 9 WHERE section_id = ?', [$sec]), PDOException::class);
    $v2 = template_new_version($id, $qa);
    t_same(1, (int) db_value('SELECT COUNT(*) FROM template_parameters tp JOIN template_sections s ON s.id = tp.section_id WHERE s.template_id = ?', [$v2]));
    t_throws(static fn () => template_new_version($id, $qa), ValidationError::class, 422, 'already a draft');
    template_publish($v2, $qa);
    t_same('RETIRED', db_value('SELECT status FROM inspection_templates WHERE id = ?', [$id]), 'publishing v2 retires v1');
    template_retire($v2, $qa);
}

function test_mapping_conflicts_are_blocked(): void
{
    $qa  = t_login('qa');
    $sca = (int) db_value("SELECT id FROM report_types WHERE code = 'SCA'");
    $id  = template_create(['report_type_id' => (string) $sca, 'template_code' => 'SCA-DUP', 'name' => 'Duplicate', 'format_doc_no' => 'QA/F/01',
        'format_rev_no' => '00', 'format_made_date' => '2026-01-01'], $qa);
    $sec = template_section_add($id, 'S', '', $qa);
    template_param_save($id, ['section_id' => (string) $sec, 'parameter_id' => (string) db_value('SELECT id FROM inspection_parameters ORDER BY id LIMIT 1'),
        'observation_type' => 'OK_NOT_OK', 'observation_count' => '1', 'is_mandatory' => '1', 'prefill_source' => 'NONE'], $qa);
    $demo = db_row("SELECT * FROM inspection_templates WHERE report_type_id = ? AND status = 'PUBLISHED'", [$sca]);
    $map  = template_mapping((int) $demo['id']);
    template_save_mapping($id, [], array_map('strval', $map['machines']), $qa);
    t_throws(static fn () => template_publish($id, $qa), ValidationError::class, 422, 'same specificity');
    template_delete($id, $qa);
}

function test_full_workflow_with_segregation_of_duties(): void
{
    $op = t_login('op');
    $id = t_start('SCA', $op);
    t_same($id, insp_start(['client_uuid' => db_value('SELECT client_uuid FROM inspection_reports WHERE id = ?', [$id])] + [
        'report_type_id' => 1, 'part_id' => 1, 'machine_id' => 1, 'inspection_date' => production_current()['date'], 'shift_id' => 1], $op), 'same tablet request = same draft');

    $saved = insp_save_draft($id, ['cells' => [['observation_id' => (int) db_value('SELECT id FROM inspection_observations WHERE report_id = ? ORDER BY id LIMIT 1', [$id]), 'reading_no' => 1, 'value' => 'abc']]], $op);
    t_same(false, $saved['ok'], 'invalid cell reported');
    t_throws(static fn () => wf_submit($id, 'DRAFT', uuid_v4(), $op), ValidationError::class, 422, 'cannot be submitted yet');

    $saved = t_fill($id, $op);
    t_same(true, $saved['ok'], 'all values saved: ' . json_encode($saved['errors']));
    t_same('PASS', $saved['overall_result']);
    $key    = uuid_v4();
    $result = wf_submit($id, 'DRAFT', $key, $op);
    t_ok(preg_match('/^SCA-\d{4}-\d{2}-\d{6}$/', $result['report_no']) === 1, 'report number format');
    t_same('PENDING_PRODUCTION', $result['status']);
    t_same(true, wf_submit($id, 'DRAFT', $key, $op)['replayed'] ?? false, 'repeated request returns the first answer');
    t_throws(static fn () => wf_submit($id, 'RETURNED', $key, $op), QmsError::class, 422, 'different data');
    t_throws(static fn () => insp_save_draft($id, ['cells' => []], $op), QmsError::class, 423);

    // The submitter can never sign, not even as Super Admin with every permission.
    $admin = t_login('admin');
    t_ok(wf_segregation_problem(insp_report($id), 'PRODUCTION', $admin) === null, 'admin did not submit');
    $pe = t_login('pe');
    t_throws(static fn () => wf_act($id, 'approve', '', 'wrong', 'PENDING_PRODUCTION', uuid_v4(), $pe), QmsError::class, 403, 'password');
    wf_act($id, 'approve', 'ok', t_password(), 'PENDING_PRODUCTION', uuid_v4(), $pe);

    // A stale page (status changed meanwhile) is refused; returning needs a reason.
    $qe = t_login('qe');
    t_throws(static fn () => wf_act($id, 'approve', '', t_password(), 'PENDING_PRODUCTION', uuid_v4(), $qe), QmsError::class, 409, 'changed since you opened it');
    t_throws(static fn () => wf_act($id, 'return', '', t_password(), 'PENDING_QUALITY', uuid_v4(), $qe), ValidationError::class, 422, 'Explain');
    wf_act($id, 'return', 'Recheck', t_password(), 'PENDING_QUALITY', uuid_v4(), $qe);
    t_same('RETURNED', insp_report($id)['status']);

    $op = t_login('op');
    t_fill($id, $op);
    wf_submit($id, 'RETURNED', uuid_v4(), $op);
    wf_act($id, 'approve', '', t_password(), 'PENDING_PRODUCTION', uuid_v4(), t_login('pe'));
    wf_act($id, 'approve', '', t_password(), 'PENDING_QUALITY', uuid_v4(), t_login('qe'));
    $qa = t_login('qa');
    t_same('approved', strtolower(substr(wf_act($id, 'approve', '', t_password(), 'PENDING_QA', uuid_v4(), $qa)['message'], -9, 8)));
    $report = insp_report($id);
    t_same('QA_APPROVED', $report['status']);
    t_same((int) $qa['id'], (int) $report['approved_by']);
    t_same(['SUBMISSION', 'PRODUCTION', 'QUALITY', 'QA'], array_column(wf_steps($report, insp_approvals($id)), 'key'));
    t_same(['done', 'done', 'done', 'done'], array_column(wf_steps($report, insp_approvals($id)), 'state'));

    // Approved data is locked in the database itself.
    t_throws(static fn () => db_exec('UPDATE inspection_readings rd JOIN inspection_observations o ON o.id = rd.observation_id SET rd.value_text = ? WHERE o.report_id = ?', ['x', $id]), PDOException::class);
    t_throws(static fn () => db_exec('DELETE FROM inspection_approvals WHERE report_id = ?', [$id]), PDOException::class);
    t_throws(static fn () => db_exec("UPDATE audit_logs SET action = 'X' ORDER BY id LIMIT 1"), PDOException::class);

    // Correction revision: the approved version stays current until the revision is approved.
    $rev = wf_revise($id, 'Typo', uuid_v4(), $qa)['report_id'];
    t_throws(static fn () => wf_revise($id, 'Again', uuid_v4(), $qa), QmsError::class, 409, 'already in progress');
    t_fill($rev, $qa);
    wf_submit($rev, 'DRAFT', uuid_v4(), $qa);
    wf_act($rev, 'approve', '', t_password(), 'PENDING_PRODUCTION', uuid_v4(), t_login('pe'));
    wf_act($rev, 'approve', '', t_password(), 'PENDING_QUALITY', uuid_v4(), t_login('qe'));
    $admin = t_login('admin');
    wf_act($rev, 'approve', '', t_password(), 'PENDING_QA', uuid_v4(), $admin);
    t_same('SUPERSEDED', insp_report($id)['status']);
    t_same([0, 1], [(int) insp_report($id)['is_current'], (int) insp_report($rev)['is_current']]);
    t_same($report['report_no'], insp_report($rev)['report_no'], 'revision keeps the number');
}

function test_qa_cannot_sign_twice_with_distinct_signers(): void
{
    $op = t_login('op');
    $id = t_start('SCA', $op, 'CNC-02');
    t_fill($id, $op);
    wf_submit($id, 'DRAFT', uuid_v4(), $op);
    $qa = t_login('qa'); // holds quality and QA permissions
    db_update('report_types', ['requires_production_verification' => 0], "code = 'SCA'");
    try {
        t_same('PENDING_PRODUCTION', insp_report($id)['status'], 'in-flight report keeps its stage');
        wf_act($id, 'approve', '', t_password(), 'PENDING_PRODUCTION', uuid_v4(), t_login('pe'));
        wf_act($id, 'approve', '', t_password(), 'PENDING_QUALITY', uuid_v4(), $qa);
        t_throws(static fn () => wf_act($id, 'approve', '', t_password(), 'PENDING_QA', uuid_v4(), $qa), QmsError::class, 403, 'already signed another stage');
        wf_act($id, 'reject', 'Wrong part', t_password(), 'PENDING_QA', uuid_v4(), t_login('admin'));
        t_same('REJECTED', insp_report($id)['status']);
    } finally {
        db_update('report_types', ['requires_production_verification' => 1], "code = 'SCA'");
    }
}

function test_view_and_edit_permissions(): void
{
    $op  = t_login('op');
    $id  = t_start('SCA', $op);
    $op2 = t_login('op2');
    t_same(false, insp_can_view(insp_report($id), $op2), 'operators see only their own reports');
    t_same(false, insp_can_edit(insp_report($id), $op2));
    t_throws(static fn () => insp_save_draft($id, ['cells' => []], $op2), QmsError::class, 403);
    $viewer = t_login('viewer');
    t_same(true, insp_can_view(insp_report($id), $viewer));
    t_same(false, insp_can_edit(insp_report($id), $viewer));
    t_same(false, wf_can_cancel(insp_report($id), $op2));
    $op = t_login('op');
    t_throws(static fn () => wf_cancel($id, '', 'DRAFT', $op), ValidationError::class, 422);
    t_same('CANCELLED', wf_cancel($id, 'Started by mistake', 'DRAFT', $op)['status']);
}

function test_in_process_sheet_rounds(): void
{
    $op    = t_login('op');
    $id    = t_start('IPR', $op, 'CNC-02');
    $same  = t_start('IPR', t_login('op2'), 'CNC-02');
    t_same($id, $same, 'operators of all shifts share the day sheet');
    $op    = t_login('op');
    $shift = (int) db_value("SELECT id FROM shifts WHERE code = 'A'");
    t_throws(static fn () => insp_add_round($id, $shift, '25:00', $op), ValidationError::class, 422);
    $round = insp_add_round($id, $shift, '08:00', $op);
    t_throws(static fn () => insp_sign_round($id, $round, $op), ValidationError::class, 422, 'missing readings');
    t_same(true, t_fill($id, $op)['ok']);
    insp_sign_round($id, $round, $op);
    t_throws(static fn () => insp_save_draft($id, ['rounds' => [['round_id' => $round, 'remarks' => 'x']]], $op), QmsError::class, 423, 'signed');

    $op2 = t_login('op2');
    $r2  = insp_add_round($id, $shift, '10:00', $op2);
    t_throws(static fn () => insp_save_draft($id, ['rounds' => [['round_id' => $round, 'remarks' => 'x']]], $op2), QmsError::class, 423);
    insp_delete_round($id, $r2, $op2);

    $qe = t_login('qe');
    insp_verify_round($id, $round, t_password(), $qe);
    t_same('VERIFIED', db_value('SELECT status FROM inspection_rounds WHERE id = ?', [$round]));
    $qa = t_login('qa');
    insp_reopen_round($id, $round, 'Correct a value', $qa);
    t_same('OPEN', db_value('SELECT status FROM inspection_rounds WHERE id = ?', [$round]));
    $op = t_login('op');
    insp_sign_round($id, $round, $op);
    $result = wf_submit($id, 'DRAFT', uuid_v4(), $op);
    t_same('PENDING_QUALITY', $result['status'], 'IPR skips production verification');
}

function test_reports_and_dashboard_queries_run(): void
{
    require_once QMS_ROOT . '/includes/reports.php';
    $today = production_current()['date'];
    foreach (array_keys(REPORT_CATALOGUE) as $name) {
        $f      = report_filters($name, ['from' => '2026-01-01', 'to' => $today, 'date' => $today, 'month' => substr($today, 0, 7), 'days' => '60'], $today);
        $paging = paging(50);
        $result = report_run($f, $paging);
        t_ok(isset($result['columns'], $result['rows']), "report {$name} runs");
    }
    $daily = report_run(report_filters('daily', ['date' => $today], $today));
    t_ok(count($daily['rows']) >= 3, 'daily report lists the test inspections');
    $f = dash_filters(['period' => 'month'], $today);
    t_ok(dash_cards($f)['total'] >= 3 && count(dash_trend($f)) >= 28, 'dashboard figures');
}
