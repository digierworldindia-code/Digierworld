<?php
/**
 * Management dashboard figures. Counts use the current revision of each report
 * (is_current = 1) and ignore cancelled reports; the approval workload counts
 * every report waiting for a signature, revisions included.
 */
defined('QMS') || exit;

require_once QMS_ROOT . '/includes/validate.php';

/** Validated dashboard filters with the date range they cover. */
function dash_filters(array $input, string $today): array
{
    $period = ($input['period'] ?? '') === 'month' ? 'month' : 'day';
    $f      = ['period' => $period];
    if ($period === 'month') {
        $start      = DateTimeImmutable::createFromFormat('!Y-m', (string) ($input['month'] ?? '')) ?: new DateTimeImmutable(substr($today, 0, 7) . '-01');
        $f['month'] = $start->format('Y-m');
        $f['from']  = $start->format('Y-m-01');
        $f['to']    = $start->format('Y-m-t');
        $f['label'] = $start->format('F Y');
    } else {
        $day        = validate_date((string) ($input['date'] ?? '')) ? new DateTimeImmutable((string) $input['date']) : new DateTimeImmutable($today);
        $f['date']  = $day->format('Y-m-d');
        $f['from']  = $f['date'];
        $f['to']    = $f['date'];
        $f['label'] = plant_date($f['date']) . ($f['date'] === $today ? ' (today)' : '');
    }
    foreach (['type', 'part', 'machine', 'shift', 'operator'] as $key) {
        $f[$key] = ctype_digit((string) ($input[$key] ?? '')) ? (int) $input[$key] : 0;
    }
    $status      = (string) ($input['status'] ?? '');
    $f['status'] = $status === 'PENDING' || isset(REPORT_STATUSES[$status]) ? $status : '';

    return $f;
}

/**
 * WHERE part shared by the dashboard and the reports (filters are bound values).
 *
 * @return array{0: string, 1: list<mixed>}
 */
function dash_where(array $f, bool $currentOnly = true): array
{
    $where  = ["r.status <> 'CANCELLED'", 'r.inspection_date BETWEEN ? AND ?'];
    $params = [$f['from'], $f['to']];
    if ($currentOnly) {
        $where[] = 'r.is_current = 1';
    }
    foreach (['type' => 'r.report_type_id', 'part' => 'r.part_id', 'machine' => 'r.machine_id'] as $key => $column) {
        if (($f[$key] ?? 0) > 0) {
            $where[]  = $column . ' = ?';
            $params[] = $f[$key];
        }
    }
    if (($f['shift'] ?? 0) > 0) {
        $where[] = '(r.shift_id = ? OR r.id IN (SELECT report_id FROM inspection_rounds WHERE shift_id = ?))';
        array_push($params, $f['shift'], $f['shift']);
    }
    if (($f['operator'] ?? 0) > 0) {
        $where[] = '(r.operator_employee_id = ? OR r.id IN (SELECT ro.report_id FROM inspection_rounds ro JOIN users u ON u.id = ro.operator_user_id WHERE u.employee_id = ?))';
        array_push($params, $f['operator'], $f['operator']);
    }
    if (($f['status'] ?? '') === 'PENDING') {
        $where[] = 'r.status IN (' . db_in(PENDING_STATUSES) . ')';
        array_push($params, ...PENDING_STATUSES);
    } elseif (($f['status'] ?? '') !== '') {
        $where[]  = 'r.status = ?';
        $params[] = $f['status'];
    }

    return [' WHERE ' . implode(' AND ', $where), $params];
}

/** @return array<string, int> */
function dash_cards(array $f): array
{
    [$w, $p]   = dash_where($f);
    [$wp, $pp] = dash_where($f, false);
    $row = db_row("SELECT COUNT(*) AS total,
                          COALESCE(SUM(r.overall_result = 'PASS' AND r.status <> 'DRAFT'), 0) AS passed,
                          COALESCE(SUM(r.overall_result = 'FAIL' AND r.status <> 'DRAFT'), 0) AS failed,
                          COALESCE(SUM(r.status IN ('DRAFT', 'RETURNED')), 0) AS in_progress,
                          COALESCE(SUM(r.oos_count), 0) AS oos, COALESCE(SUM(r.oos_count > 0), 0) AS oos_reports
                     FROM inspection_reports r" . $w, $p);
    $pending = (int) db_value('SELECT COUNT(*) FROM inspection_reports r' . $wp . ' AND r.status IN (' . db_in(PENDING_STATUSES) . ')', [...$pp, ...PENDING_STATUSES]);

    return array_map('intval', $row) + ['pending' => $pending];
}

/**
 * Pass / fail per production day: 14 days up to the selected day, or every day of the month.
 *
 * @return list<array{date: string, pass: int, fail: int, other: int}>
 */
function dash_trend(array $f): array
{
    $to      = new DateTimeImmutable($f['to']);
    $from    = $f['period'] === 'month' ? new DateTimeImmutable($f['from']) : $to->modify('-13 days');
    [$w, $p] = dash_where(['from' => $from->format('Y-m-d')] + $f);
    $rows    = db_all("SELECT r.inspection_date AS d, SUM(r.overall_result = 'PASS' AND r.status <> 'DRAFT') AS pass,
                              SUM(r.overall_result = 'FAIL' AND r.status <> 'DRAFT') AS fail, COUNT(*) AS total
                         FROM inspection_reports r" . $w . ' GROUP BY r.inspection_date', $p);
    $byDate  = array_column($rows, null, 'd');

    $out = [];
    for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
        $key   = $day->format('Y-m-d');
        $row   = $byDate[$key] ?? ['pass' => 0, 'fail' => 0, 'total' => 0];
        $out[] = ['date' => $key, 'pass' => (int) $row['pass'], 'fail' => (int) $row['fail'], 'other' => (int) $row['total'] - (int) $row['pass'] - (int) $row['fail']];
    }

    return $out;
}

function dash_by_type(array $f): array
{
    [$w, $p] = dash_where($f);

    return db_all("SELECT rt.code, rt.name, COUNT(*) AS total, SUM(r.status IN ('DRAFT','RETURNED')) AS in_progress,
                          SUM(r.status IN ('PENDING_PRODUCTION','PENDING_QUALITY','PENDING_QA')) AS pending,
                          SUM(r.status = 'QA_APPROVED') AS approved, SUM(r.status = 'REJECTED') AS rejected, SUM(r.overall_result = 'FAIL') AS failed
                     FROM inspection_reports r JOIN report_types rt ON rt.id = r.report_type_id" . $w . '
                    GROUP BY rt.id, rt.code, rt.name ORDER BY rt.id', $p);
}

/** Parameters with the most out-of-specification readings. */
function dash_top_oos(array $f, int $limit = 8): array
{
    [$w, $p] = dash_where($f);

    return db_all("SELECT ip.code, ip.name, COUNT(*) AS readings, COUNT(DISTINCT r.id) AS reports
                     FROM inspection_reports r
                     JOIN inspection_observations o ON o.report_id = r.id
                     JOIN inspection_readings rd ON rd.observation_id = o.id
                     JOIN template_parameters tp ON tp.id = o.template_parameter_id
                     JOIN inspection_parameters ip ON ip.id = tp.parameter_id" . $w . " AND rd.result = 'FAIL'
                    GROUP BY ip.id, ip.code, ip.name ORDER BY readings DESC LIMIT ?", [...$p, $limit]);
}

function dash_machines(array $f, int $limit = 10): array
{
    [$w, $p] = dash_where($f);

    return db_all("SELECT m.machine_code, m.machine_name, COUNT(*) AS total, SUM(r.overall_result = 'FAIL') AS failed, SUM(r.oos_count) AS oos
                     FROM inspection_reports r JOIN machines m ON m.id = r.machine_id" . $w . '
                    GROUP BY m.id, m.machine_code, m.machine_name ORDER BY failed DESC, total DESC LIMIT ?', [...$p, $limit]);
}

/** Reports waiting for a signature for more than $hours (any date). */
function dash_overdue(int $hours = 24, int $limit = 10): array
{
    return db_all('SELECT r.id, r.report_no, r.revision_no, r.status, r.submitted_at, rt.code AS type_code, p.part_number, m.machine_code
                     FROM inspection_reports r
                     JOIN report_types rt ON rt.id = r.report_type_id
                     JOIN parts p ON p.id = r.part_id
                     JOIN machines m ON m.id = r.machine_id
                    WHERE r.status IN (' . db_in(PENDING_STATUSES) . ') AND r.submitted_at < ?
                    ORDER BY r.submitted_at LIMIT ?', [...PENDING_STATUSES, now_utc()->modify("-{$hours} hours")->format('Y-m-d H:i:s'), $limit]);
}

/** @return array{expired: int, soon: int} */
function dash_gauge_alerts(string $today): array
{
    $soon = (new DateTimeImmutable($today))->modify('+' . max(0, setting_int('gauge.due_warning_days', 7)) . ' days')->format('Y-m-d');
    $from = " FROM gauges g JOIN gauge_types t ON t.id = g.gauge_type_id WHERE g.status = 'ACTIVE' AND t.requires_calibration = 1";

    return [
        'expired' => (int) db_value('SELECT COUNT(*)' . $from . ' AND (g.calibration_due_date < ? OR g.calibration_due_date IS NULL)', [$today]),
        'soon'    => (int) db_value('SELECT COUNT(*)' . $from . ' AND g.calibration_due_date BETWEEN ? AND ?', [$today, $soon]),
    ];
}
