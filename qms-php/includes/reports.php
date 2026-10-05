<?php
/**
 * The 8 management reports. Every report is a fixed query written here; the
 * filters are validated and passed as bound values (no SQL from the user).
 * Counts use the current revision of each report and ignore cancelled ones.
 *
 * Column types drive formatting on screen and in CSV:
 * text, int, decimal, percent, date, datetime, status, result.
 */
defined('QMS') || exit;

require_once QMS_ROOT . '/includes/dashboard.php';
require_once QMS_ROOT . '/includes/inspections.php';

const REPORT_EXPORT_LIMIT = 50000;

const REPORT_CATALOGUE = [
    'daily'     => ['title' => 'Daily report', 'description' => 'Every inspection report of one production date.', 'icon' => 'bi-calendar-day', 'period' => 'day', 'filters' => ['type', 'part', 'machine', 'shift', 'status']],
    'monthly'   => ['title' => 'Monthly report', 'description' => 'Day-by-day totals of a month: inspections, results, approvals, OOS and rejections.', 'icon' => 'bi-calendar3', 'period' => 'month', 'filters' => ['type', 'part', 'machine']],
    'machine'   => ['title' => 'Machine-wise report', 'description' => 'Inspections, failures and out-of-spec readings per machine.', 'icon' => 'bi-gear-wide-connected', 'period' => 'range', 'filters' => ['type', 'part']],
    'part'      => ['title' => 'Part-wise report', 'description' => 'Inspections, failures and out-of-spec readings per part.', 'icon' => 'bi-box-seam', 'period' => 'range', 'filters' => ['type', 'machine']],
    'operator'  => ['title' => 'Operator-wise report', 'description' => 'Inspections and results per operator (In-Process rounds count for the operator who signed them).', 'icon' => 'bi-person-badge', 'period' => 'range', 'filters' => ['type', 'machine']],
    'rejection' => ['title' => 'Rejection report', 'description' => 'Rejected and rework quantities from In-Process inspections, and rejected reports.', 'icon' => 'bi-trash3', 'period' => 'range', 'filters' => ['part', 'machine', 'shift']],
    'oos'       => ['title' => 'Out-of-specification report', 'description' => 'Every reading outside LSL – USL with its gauge and remarks.', 'icon' => 'bi-exclamation-octagon', 'period' => 'range', 'filters' => ['type', 'part', 'machine', 'shift']],
    'gauge-due' => ['title' => 'Gauge calibration due report', 'description' => 'Gauges out of calibration or due within the chosen number of days.', 'icon' => 'bi-rulers', 'period' => 'none', 'filters' => ['days']],
];

/** Validated filters of one report. */
function report_filters(string $name, array $input, string $today): array
{
    $def = REPORT_CATALOGUE[$name] ?? not_found('Unknown report.');
    $f   = ['name' => $name];
    $date = static fn (mixed $v): ?string => is_string($v) && validate_date($v) ? $v : null;

    switch ($def['period']) {
        case 'day':
            $f['from']  = $f['to'] = $date($input['date'] ?? null) ?? $today;
            $f['label'] = plant_date($f['from']);
            break;
        case 'month':
            $m          = is_string($input['month'] ?? null) ? DateTimeImmutable::createFromFormat('!Y-m', $input['month']) : false;
            $m          = $m ?: new DateTimeImmutable(substr($today, 0, 7) . '-01');
            $f['month'] = $m->format('Y-m');
            $f['from']  = $m->format('Y-m-01');
            $f['to']    = $m->format('Y-m-t');
            $f['label'] = $m->format('F Y');
            break;
        case 'range':
            $f['from'] = $date($input['from'] ?? null) ?? (new DateTimeImmutable($today))->modify('-29 days')->format('Y-m-d');
            $f['to']   = $date($input['to'] ?? null) ?? $today;
            if ($f['to'] < $f['from']) {
                [$f['from'], $f['to']] = [$f['to'], $f['from']];
            }
            // Keep queries bounded: at most two years at a time.
            $limit = (new DateTimeImmutable($f['to']))->modify('-731 days')->format('Y-m-d');
            if ($f['from'] < $limit) {
                $f['from'] = $limit;
            }
            $f['label'] = plant_date($f['from']) . ' – ' . plant_date($f['to']);
            break;
        default:
            $f['days']  = max(0, min(365, ctype_digit((string) ($input['days'] ?? '')) ? (int) $input['days'] : 30));
            $f['label'] = 'Due within ' . $f['days'] . ' days of ' . plant_date($today);
            $f['today'] = $today;
    }
    foreach (['type', 'part', 'machine', 'shift', 'operator'] as $key) {
        $f[$key] = ctype_digit((string) ($input[$key] ?? '')) ? (int) $input[$key] : 0;
    }
    $status      = (string) ($input['status'] ?? '');
    $f['status'] = $status === 'PENDING' || isset(REPORT_STATUSES[$status]) ? $status : '';

    return $f;
}

/**
 * Runs a report. $paging null = export (all rows up to REPORT_EXPORT_LIMIT).
 *
 * @return array{columns: array<string, array{label: string, type: string}>, rows: list<array>, totals: ?array, link?: string}
 */
function report_run(array $f, ?array &$paging = null): array
{
    return match ($f['name']) {
        'daily'     => report_daily($f, $paging),
        'monthly'   => report_monthly($f),
        'machine'   => report_grouped($f, 'm.id', ['machine_code' => ['m.machine_code', 'Machine'], 'machine_name' => ['m.machine_name', 'Name']], 'JOIN machines m ON m.id = r.machine_id'),
        'part'      => report_grouped($f, 'p.id', ['part_number' => ['p.part_number', 'Part number'], 'part_name' => ['p.part_name', 'Part name']], 'JOIN parts p ON p.id = r.part_id'),
        'operator'  => report_operators($f),
        'rejection' => report_rejections($f, $paging),
        'oos'       => report_oos($f, $paging),
        'gauge-due' => report_gauges_due($f),
    };
}

function report_daily(array $f, ?array &$paging): array
{
    [$w, $p] = dash_where($f);
    $sql = "SELECT r.id, r.report_no, r.revision_no, rt.code AS type_code, p.part_number, m.machine_code,
                   COALESCE(s.code, (SELECT GROUP_CONCAT(DISTINCT s2.code ORDER BY s2.sort_order SEPARATOR ', ')
                                       FROM inspection_rounds ro2 JOIN shifts s2 ON s2.id = ro2.shift_id WHERE ro2.report_id = r.id)) AS shift_code,
                   COALESCE(op.full_name, cu.username) AS operator_name, r.overall_result, r.oos_count, r.status, r.submitted_at, r.approved_at
              FROM inspection_reports r
              JOIN report_types rt ON rt.id = r.report_type_id
              JOIN parts p ON p.id = r.part_id
              JOIN machines m ON m.id = r.machine_id
              LEFT JOIN shifts s ON s.id = r.shift_id
              LEFT JOIN employees op ON op.id = r.operator_employee_id
              LEFT JOIN users cu ON cu.id = r.created_by" . $w . ' ORDER BY rt.id, r.id';

    return [
        'columns' => report_columns([
            'report_no' => ['Report no.', 'text'], 'revision_no' => ['Rev', 'int'], 'type_code' => ['Type', 'text'],
            'part_number' => ['Part', 'text'], 'machine_code' => ['Machine', 'text'], 'shift_code' => ['Shift', 'text'],
            'operator_name' => ['Operator', 'text'], 'overall_result' => ['Result', 'result'], 'oos_count' => ['OOS', 'int'],
            'status' => ['Status', 'status'], 'submitted_at' => ['Submitted', 'datetime'], 'approved_at' => ['Approved', 'datetime'],
        ]),
        'rows'    => report_page($sql, $p, $paging),
        'totals'  => null,
        'link'    => 'inspection',
    ];
}

function report_monthly(array $f): array
{
    [$w, $p] = dash_where($f);
    $rows = db_all("SELECT r.inspection_date AS day, COUNT(*) AS total,
                           SUM(r.overall_result = 'PASS' AND r.status <> 'DRAFT') AS passed,
                           SUM(r.overall_result = 'FAIL' AND r.status <> 'DRAFT') AS failed,
                           SUM(r.status IN ('PENDING_PRODUCTION','PENDING_QUALITY','PENDING_QA')) AS pending,
                           SUM(r.status = 'QA_APPROVED') AS approved, SUM(r.status = 'REJECTED') AS rejected_reports,
                           SUM(r.oos_count) AS oos,
                           SUM((SELECT COALESCE(SUM(ro.rejected_qty), 0) FROM inspection_rounds ro WHERE ro.report_id = r.id)) AS rejected_qty
                      FROM inspection_reports r" . $w . ' GROUP BY r.inspection_date ORDER BY r.inspection_date', $p);
    $byDay  = array_column($rows, null, 'day');
    $keys   = ['total', 'passed', 'failed', 'pending', 'approved', 'rejected_reports', 'oos', 'rejected_qty'];
    $totals = ['day' => 'Total'] + array_fill_keys($keys, 0);
    $out    = [];
    for ($d = new DateTimeImmutable($f['from']); $d->format('Y-m-d') <= $f['to']; $d = $d->modify('+1 day')) {
        $key = $d->format('Y-m-d');
        $row = ['day' => $key];
        foreach ($keys as $k) {
            $row[$k]     = (int) ($byDay[$key][$k] ?? 0);
            $totals[$k] += $row[$k];
        }
        $out[] = $row;
    }

    return [
        'columns' => report_columns([
            'day' => ['Date', 'date'], 'total' => ['Inspections', 'int'], 'passed' => ['Passed', 'int'], 'failed' => ['Failed', 'int'],
            'pending' => ['Pending approval', 'int'], 'approved' => ['Approved', 'int'], 'rejected_reports' => ['Rejected reports', 'int'],
            'oos' => ['OOS readings', 'int'], 'rejected_qty' => ['Rejected qty', 'int'],
        ]),
        'rows'    => $out,
        'totals'  => $totals,
    ];
}

/**
 * Machine-wise / part-wise summary.
 *
 * @param array<string, array{0: string, 1: string}> $keys alias => [column, label]
 */
function report_grouped(array $f, string $groupBy, array $keys, string $join): array
{
    [$w, $p] = dash_where($f);
    $select  = [];
    $columns = [];
    foreach ($keys as $alias => [$column, $label]) {
        $select[]        = $column . ' AS ' . $alias;
        $columns[$alias] = [$label, 'text'];
    }
    $rows = db_all('SELECT ' . implode(', ', $select) . ", COUNT(*) AS total,
                           SUM(r.overall_result = 'PASS' AND r.status <> 'DRAFT') AS passed,
                           SUM(r.overall_result = 'FAIL' AND r.status <> 'DRAFT') AS failed,
                           SUM(r.oos_count) AS oos, SUM(r.status = 'REJECTED') AS rejected_reports, MAX(r.inspection_date) AS last_date
                      FROM inspection_reports r {$join}" . $w . '
                     GROUP BY ' . $groupBy . ', ' . implode(', ', array_column($keys, 0)) . ' ORDER BY failed DESC, total DESC', $p);

    return [
        'columns' => report_columns($columns + [
            'total' => ['Inspections', 'int'], 'passed' => ['Passed', 'int'], 'failed' => ['Failed', 'int'], 'pass_rate' => ['Pass rate', 'percent'],
            'oos' => ['OOS readings', 'int'], 'rejected_reports' => ['Rejected reports', 'int'], 'last_date' => ['Last inspection', 'date'],
        ]),
        'rows'    => array_map(static fn (array $r): array => $r + ['pass_rate' => report_rate((int) $r['passed'], (int) $r['passed'] + (int) $r['failed'])], $rows),
        'totals'  => null,
    ];
}

/** Per operator: header operator (SCA / PPI) or the operator who signed each In-Process round. */
function report_operators(array $f): array
{
    [$w, $p] = dash_where($f);
    $header  = db_all("SELECT e.id, e.employee_code, e.full_name, COUNT(*) AS reports,
                              SUM(r.overall_result = 'PASS' AND r.status <> 'DRAFT') AS passed,
                              SUM(r.overall_result = 'FAIL' AND r.status <> 'DRAFT') AS failed, SUM(r.oos_count) AS oos
                         FROM inspection_reports r JOIN employees e ON e.id = r.operator_employee_id" . $w . '
                        GROUP BY e.id, e.employee_code, e.full_name', $p);
    $rounds  = db_all("SELECT e.id, e.employee_code, e.full_name, COUNT(*) AS rounds,
                              SUM((SELECT COUNT(*) FROM inspection_readings rd JOIN inspection_observations o ON o.id = rd.observation_id
                                    WHERE o.round_id = ro.id AND rd.result = 'FAIL')) AS oos
                         FROM inspection_reports r
                         JOIN inspection_rounds ro ON ro.report_id = r.id
                         JOIN users u ON u.id = ro.operator_user_id
                         JOIN employees e ON e.id = u.employee_id" . $w . '
                        GROUP BY e.id, e.employee_code, e.full_name', $p);

    $out = [];
    foreach ($header as $r) {
        $out[$r['id']] = ['employee_code' => $r['employee_code'], 'full_name' => $r['full_name'], 'reports' => (int) $r['reports'],
            'passed' => (int) $r['passed'], 'failed' => (int) $r['failed'], 'rounds' => 0, 'oos' => (int) $r['oos']];
    }
    foreach ($rounds as $r) {
        $out[$r['id']] ??= ['employee_code' => $r['employee_code'], 'full_name' => $r['full_name'], 'reports' => 0, 'passed' => 0, 'failed' => 0, 'rounds' => 0, 'oos' => 0];
        $out[$r['id']]['rounds'] += (int) $r['rounds'];
        $out[$r['id']]['oos']    += (int) $r['oos'];
    }
    $rows = array_values($out);
    usort($rows, static fn (array $a, array $b): int => [$b['reports'] + $b['rounds'], $a['full_name']] <=> [$a['reports'] + $a['rounds'], $b['full_name']]);

    return [
        'columns' => report_columns([
            'employee_code' => ['Employee ID', 'text'], 'full_name' => ['Operator', 'text'], 'reports' => ['Reports (header operator)', 'int'],
            'passed' => ['Passed', 'int'], 'failed' => ['Failed', 'int'], 'rounds' => ['In-Process inspections signed', 'int'], 'oos' => ['OOS readings', 'int'],
        ]),
        'rows'    => $rows,
        'totals'  => null,
    ];
}

function report_rejections(array $f, ?array &$paging): array
{
    [$w, $p] = dash_where($f);
    $sql = "SELECT r.id, r.inspection_date, r.report_no, r.revision_no, p.part_number, m.machine_code, s.code AS shift_code,
                   ro.round_no, ro.inspection_time, ro.lot_status, ro.accepted_qty, ro.rejected_qty, ro.rework_qty, ro.remarks
              FROM inspection_reports r
              JOIN inspection_rounds ro ON ro.report_id = r.id
              JOIN parts p ON p.id = r.part_id
              JOIN machines m ON m.id = r.machine_id
              LEFT JOIN shifts s ON s.id = ro.shift_id" . $w . "
               AND (ro.rejected_qty > 0 OR ro.rework_qty > 0 OR ro.lot_status IN ('REJECTED', 'REWORK', 'ON_HOLD') OR r.status = 'REJECTED')
             ORDER BY r.inspection_date, r.id, ro.round_no";
    $sum  = db_row('SELECT COALESCE(SUM(ro.accepted_qty), 0) AS a, COALESCE(SUM(ro.rejected_qty), 0) AS rj, COALESCE(SUM(ro.rework_qty), 0) AS rw
                      FROM inspection_reports r JOIN inspection_rounds ro ON ro.report_id = r.id' . $w, $p);
    $rows = report_page($sql, $p, $paging);
    foreach ($rows as &$row) {
        $row['lot_status'] = LOT_STATUSES[$row['lot_status']] ?? '';
    }

    return [
        'columns' => report_columns([
            'inspection_date' => ['Date', 'date'], 'report_no' => ['Report no.', 'text'], 'revision_no' => ['Rev', 'int'], 'part_number' => ['Part', 'text'],
            'machine_code' => ['Machine', 'text'], 'shift_code' => ['Shift', 'text'], 'round_no' => ['Insp. #', 'int'], 'inspection_time' => ['Time', 'text'],
            'lot_status' => ['Lot status', 'text'], 'accepted_qty' => ['Accepted', 'int'], 'rejected_qty' => ['Rejected', 'int'], 'rework_qty' => ['Rework', 'int'],
            'remarks' => ['Remarks', 'text'],
        ]),
        'rows'    => $rows,
        'totals'  => ['inspection_date' => 'Period total (all inspections)', 'accepted_qty' => (int) $sum['a'], 'rejected_qty' => (int) $sum['rj'], 'rework_qty' => (int) $sum['rw'],
            'rejection_rate' => report_rate((int) $sum['rj'], (int) $sum['a'] + (int) $sum['rj'] + (int) $sum['rw'])],
        'link'    => 'inspection',
    ];
}

function report_oos(array $f, ?array &$paging): array
{
    [$w, $p] = dash_where($f);
    $sql = "SELECT r.id, r.inspection_date, r.report_no, r.revision_no, rt.code AS type_code, p.part_number, m.machine_code,
                   COALESCE(s.code, rs.code) AS shift_code, ro.round_no, tp.name AS parameter, tp.decimal_places, o.lsl, o.usl,
                   rd.reading_no, rd.value_numeric, rd.value_choice, rd.value_date, g.gauge_code, o.remarks, r.status
              FROM inspection_reports r
              JOIN report_types rt ON rt.id = r.report_type_id
              JOIN inspection_observations o ON o.report_id = r.id
              JOIN inspection_readings rd ON rd.observation_id = o.id
              JOIN inspection_rounds ro ON ro.id = o.round_id
              JOIN template_parameters tp ON tp.id = o.template_parameter_id
              JOIN parts p ON p.id = r.part_id
              JOIN machines m ON m.id = r.machine_id
              LEFT JOIN shifts s ON s.id = r.shift_id
              LEFT JOIN shifts rs ON rs.id = ro.shift_id
              LEFT JOIN gauges g ON g.id = o.gauge_id" . $w . " AND rd.result = 'FAIL'
             ORDER BY r.inspection_date, r.id, ro.round_no, tp.sort_order, rd.reading_no";
    $rows = report_page($sql, $p, $paging);
    foreach ($rows as &$row) {
        $places       = (int) $row['decimal_places'];
        $row['value'] = $row['value_numeric'] !== null ? qms_decimal($row['value_numeric'], $places)
            : ($row['value_choice'] !== null ? str_replace('_', ' ', $row['value_choice']) : (string) $row['value_date']);
        $row['lsl']   = $row['lsl'] === null ? '' : qms_decimal($row['lsl'], $places);
        $row['usl']   = $row['usl'] === null ? '' : qms_decimal($row['usl'], $places);
    }

    return [
        'columns' => report_columns([
            'inspection_date' => ['Date', 'date'], 'report_no' => ['Report no.', 'text'], 'revision_no' => ['Rev', 'int'], 'type_code' => ['Type', 'text'],
            'part_number' => ['Part', 'text'], 'machine_code' => ['Machine', 'text'], 'shift_code' => ['Shift', 'text'], 'round_no' => ['Insp. #', 'int'],
            'parameter' => ['Parameter', 'text'], 'lsl' => ['LSL', 'text'], 'usl' => ['USL', 'text'], 'reading_no' => ['Obs.', 'int'],
            'value' => ['Value', 'text'], 'gauge_code' => ['Gauge ID', 'text'], 'remarks' => ['Remarks', 'text'], 'status' => ['Status', 'status'],
        ]),
        'rows'    => $rows,
        'totals'  => null,
        'link'    => 'inspection',
    ];
}

function report_gauges_due(array $f): array
{
    $limit = (new DateTimeImmutable($f['today']))->modify('+' . $f['days'] . ' days')->format('Y-m-d');
    $rows  = db_all("SELECT g.id, g.gauge_code, g.gauge_name, t.name AS type_name, g.location, g.last_calibration_date, g.calibration_due_date, g.status,
                            DATEDIFF(g.calibration_due_date, ?) AS days_left
                       FROM gauges g JOIN gauge_types t ON t.id = g.gauge_type_id
                      WHERE t.requires_calibration = 1 AND g.status <> 'RETIRED' AND (g.calibration_due_date IS NULL OR g.calibration_due_date <= ?)
                      ORDER BY g.calibration_due_date IS NULL DESC, g.calibration_due_date", [$f['today'], $limit]);
    foreach ($rows as &$row) {
        $row['state']  = match (true) {
            $row['calibration_due_date'] === null => 'Never calibrated',
            (int) $row['days_left'] < 0           => 'EXPIRED',
            default                               => 'Due in ' . (int) $row['days_left'] . ' day(s)',
        };
        $row['status'] = GAUGE_STATUSES[$row['status']] ?? $row['status'];
    }

    return [
        'columns' => report_columns([
            'gauge_code' => ['Gauge ID', 'text'], 'gauge_name' => ['Name', 'text'], 'type_name' => ['Type', 'text'], 'location' => ['Location', 'text'],
            'last_calibration_date' => ['Last calibration', 'date'], 'calibration_due_date' => ['Due date', 'date'], 'state' => ['Calibration', 'text'],
            'status' => ['Gauge status', 'text'],
        ]),
        'rows'    => $rows,
        'totals'  => null,
        'link'    => 'gauge',
    ];
}

// ---------------------------------------------------------------- helpers

/** One page of rows (screen) or all rows up to the export limit (CSV). */
function report_page(string $sql, array $params, ?array &$paging): array
{
    if ($paging === null) {
        return db_all($sql . ' LIMIT ' . REPORT_EXPORT_LIMIT, $params);
    }
    $paging['total'] = (int) db_value('SELECT COUNT(*) FROM (' . $sql . ') counted', $params);

    return db_all($sql . ' LIMIT ? OFFSET ?', [...$params, $paging['per_page'], $paging['offset']]);
}

/** @param array<string, array{0: string, 1: string}> $columns key => [label, type] */
function report_columns(array $columns): array
{
    return array_map(static fn (array $c): array => ['label' => $c[0], 'type' => $c[1]], $columns);
}

function report_rate(int $part, int $whole): ?string
{
    return $whole === 0 ? null : number_format($part * 100 / $whole, 1, '.', '');
}

/** Screen formatting of one cell (HTML). */
function report_cell(mixed $value, string $type): string
{
    if ($value === null || $value === '') {
        return '';
    }

    return match ($type) {
        'date'     => e(plant_date((string) $value)),
        'datetime' => e(plant_dt((string) $value)),
        'status'   => status_badge((string) $value),
        'result'   => result_badge((string) $value),
        'percent'  => e((string) $value) . ' %',
        default    => e((string) $value),
    };
}

// ---------------------------------------------------------------- CSV

/**
 * CSV / formula injection: text starting with = + - @ TAB or CR would run as a
 * formula in Excel / Sheets, so it gets an apostrophe in front. Plain negative
 * numbers are left as they are.
 */
function csv_neutralise(string $value): string
{
    if ($value === '' || ! in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return $value;
    }
    if (preg_match('/^-\d+(\.\d+)?$/', $value) === 1) {
        return $value;
    }

    return "'" . $value;
}

/** CSV text (UTF-8 with BOM for Excel). */
function csv_build(array $columns, array $rows, ?array $totals = null): string
{
    $line = static function (array $row, bool $isTotal) use ($columns): array {
        $out = [];
        foreach ($columns as $key => $column) {
            $value = $row[$key] ?? null;
            if ($value === null || $value === '') {
                $out[] = '';

                continue;
            }
            $text  = match ($isTotal ? 'text' : $column['type']) {
                'datetime' => to_plant((string) $value, 'Y-m-d H:i'),
                'status'   => status_label((string) $value),
                'result'   => (string) $value === 'FAIL' ? 'OUT OF SPEC' : (string) $value,
                default    => (string) $value,
            };
            $out[] = csv_neutralise($text);
        }

        return $out;
    };

    $fh = fopen('php://temp', 'w+b');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, array_map(static fn (array $c): string => $c['label'], array_values($columns)), ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($fh, $line($row, false), ',', '"', '');
    }
    if ($totals !== null) {
        fputcsv($fh, $line($totals, true), ',', '"', '');
    }
    rewind($fh);
    $csv = (string) stream_get_contents($fh);
    fclose($fh);

    return $csv;
}
