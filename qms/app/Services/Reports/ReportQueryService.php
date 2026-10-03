<?php

namespace App\Services\Reports;

use App\Enums\ReportStatus;
use App\Exceptions\NotFoundException;
use App\Libraries\Paging;
use App\Services\Settings\SettingsService;
use App\Services\Support\Clock;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/**
 * Management reports. Every report is a fixed, parameterised query (no
 * user-supplied SQL): filters are validated and bound by the query builder.
 * Counts use the current revision of each report and ignore cancelled ones.
 *
 * Column types drive formatting on screen and in CSV:
 * text, int, decimal, percent, date, datetime, status, result.
 */
class ReportQueryService
{
    public const EXPORT_LIMIT = 50_000;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * @return array<string, array{title: string, description: string, icon: string, period: string, filters: list<string>}>
     */
    public static function catalogue(): array
    {
        return [
            'daily'     => ['title' => 'Daily report', 'description' => 'Every inspection report of one production date.', 'icon' => 'bi-calendar-day', 'period' => 'day', 'filters' => ['type', 'part', 'machine', 'shift', 'status']],
            'monthly'   => ['title' => 'Monthly report', 'description' => 'Day-by-day totals of a month: inspections, results, approvals, OOS and rejections.', 'icon' => 'bi-calendar3', 'period' => 'month', 'filters' => ['type', 'part', 'machine']],
            'machine'   => ['title' => 'Machine-wise report', 'description' => 'Inspections, failures and out-of-spec readings per machine.', 'icon' => 'bi-gear-wide-connected', 'period' => 'range', 'filters' => ['type', 'part']],
            'part'      => ['title' => 'Part-wise report', 'description' => 'Inspections, failures and out-of-spec readings per part.', 'icon' => 'bi-box-seam', 'period' => 'range', 'filters' => ['type', 'machine']],
            'operator'  => ['title' => 'Operator-wise report', 'description' => 'Inspections and results per operator (In-Process rounds count for the operator who signed them).', 'icon' => 'bi-person-badge', 'period' => 'range', 'filters' => ['type', 'machine']],
            'rejection' => ['title' => 'Rejection report', 'description' => 'Rejected and rework quantities from In-Process inspections, and rejected reports.', 'icon' => 'bi-trash3', 'period' => 'range', 'filters' => ['part', 'machine', 'shift']],
            'oos'       => ['title' => 'Out-of-specification report', 'description' => 'Every reading outside LSL – USL with its gauge and remarks.', 'icon' => 'bi-exclamation-octagon', 'period' => 'range', 'filters' => ['type', 'part', 'machine', 'shift']],
            'gauge-due' => ['title' => 'Gauge calibration due report', 'description' => 'Gauges out of calibration or due within the chosen number of days.', 'icon' => 'bi-rulers', 'period' => 'none', 'filters' => ['days']],
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function filters(string $slug, array $input, string $today): array
    {
        $def = self::catalogue()[$slug] ?? throw new NotFoundException('Unknown report.');
        $f   = ['slug' => $slug];

        $date = static fn (mixed $v): ?string => is_string($v) && ($d = DateTimeImmutable::createFromFormat('!Y-m-d', $v)) !== false ? $d->format('Y-m-d') : null;
        switch ($def['period']) {
            case 'day':
                $f['from'] = $f['to'] = $date($input['date'] ?? null) ?? $today;
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
                $f['days']  = max(0, min(365, (int) ($input['days'] ?? 30)));
                $f['label'] = 'Due within ' . $f['days'] . ' days of ' . plant_date($today);
                $f['today'] = $today;
        }
        foreach (['type', 'part', 'machine', 'shift', 'operator'] as $key) {
            $f[$key] = max(0, (int) ($input[$key] ?? 0));
        }
        $status      = (string) ($input['status'] ?? '');
        $f['status'] = $status === 'PENDING' || ReportStatus::tryFrom($status) !== null ? $status : '';

        return $f;
    }

    /**
     * @param array<string, mixed> $f
     *
     * @return array{columns: array<string, array{label: string, type: string}>, rows: list<array<string, mixed>>, totals: array<string, mixed>|null}
     */
    public function run(array $f, ?Paging $paging = null): array
    {
        return match ($f['slug']) {
            'daily'     => $this->daily($f, $paging),
            'monthly'   => $this->monthly($f),
            'machine'   => $this->grouped($f, 'm.id', ['m.machine_code' => ['Machine', 'text'], 'm.machine_name' => ['Name', 'text']], 'machines m', 'm.id = r.machine_id'),
            'part'      => $this->grouped($f, 'p.id', ['p.part_number' => ['Part number', 'text'], 'p.part_name' => ['Part name', 'text']], 'parts p', 'p.id = r.part_id'),
            'operator'  => $this->operators($f),
            'rejection' => $this->rejections($f, $paging),
            'oos'       => $this->oos($f, $paging),
            'gauge-due' => $this->gaugesDue($f),
        };
    }

    // ================================================================ reports

    /**
     * @param array<string, mixed> $f
     *
     * @return array<string, mixed>
     */
    private function daily(array $f, ?Paging $paging): array
    {
        $b = $this->base($f)
            ->select("r.id, r.report_no, r.revision_no, rt.code AS type_code, p.part_number, m.machine_code,
                      COALESCE(s.code, (SELECT GROUP_CONCAT(DISTINCT s2.code ORDER BY s2.sort_order SEPARATOR ', ')
                                        FROM inspection_rounds ro2 JOIN shifts s2 ON s2.id = ro2.shift_id WHERE ro2.report_id = r.id)) AS shift_code,
                      COALESCE(op.full_name, cu.username) AS operator_name, r.overall_result, r.oos_count, r.status, r.submitted_at, r.approved_at", false)
            ->join('report_types rt', 'rt.id = r.report_type_id')
            ->join('parts p', 'p.id = r.part_id')
            ->join('machines m', 'm.id = r.machine_id')
            ->join('shifts s', 's.id = r.shift_id', 'left')
            ->join('employees op', 'op.id = r.operator_employee_id', 'left')
            ->join('users cu', 'cu.id = r.created_by', 'left')
            ->orderBy('rt.id')->orderBy('r.id');

        return [
            'columns' => $this->columns([
                'report_no' => ['Report no.', 'text'], 'revision_no' => ['Rev', 'int'], 'type_code' => ['Type', 'text'],
                'part_number' => ['Part', 'text'], 'machine_code' => ['Machine', 'text'], 'shift_code' => ['Shift', 'text'],
                'operator_name' => ['Operator', 'text'], 'overall_result' => ['Result', 'result'], 'oos_count' => ['OOS', 'int'],
                'status' => ['Status', 'status'], 'submitted_at' => ['Submitted', 'datetime'], 'approved_at' => ['Approved', 'datetime'],
            ]),
            'rows'    => $this->page($b, $paging),
            'totals'  => null,
            'link'    => 'id',
        ];
    }

    /**
     * @param array<string, mixed> $f
     *
     * @return array<string, mixed>
     */
    private function monthly(array $f): array
    {
        $rows = $this->base($f)
            ->select("r.inspection_date AS day, COUNT(*) AS total,
                      SUM(r.overall_result = 'PASS' AND r.status <> 'DRAFT') AS passed,
                      SUM(r.overall_result = 'FAIL' AND r.status <> 'DRAFT') AS failed,
                      SUM(r.status IN ('PENDING_PRODUCTION','PENDING_QUALITY','PENDING_QA')) AS pending,
                      SUM(r.status = 'QA_APPROVED') AS approved, SUM(r.status = 'REJECTED') AS rejected_reports,
                      SUM(r.oos_count) AS oos,
                      SUM((SELECT COALESCE(SUM(ro.rejected_qty), 0) FROM inspection_rounds ro WHERE ro.report_id = r.id)) AS rejected_qty", false)
            ->groupBy('r.inspection_date')->orderBy('r.inspection_date')->get()->getResultArray();
        $byDay = array_column($rows, null, 'day');

        $out    = [];
        $totals = ['day' => 'Total', 'total' => 0, 'passed' => 0, 'failed' => 0, 'pending' => 0, 'approved' => 0, 'rejected_reports' => 0, 'oos' => 0, 'rejected_qty' => 0];
        for ($d = new DateTimeImmutable($f['from']); $d->format('Y-m-d') <= $f['to']; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            $row = ['day' => $key] + array_map('intval', array_diff_key($byDay[$key] ?? array_fill_keys(array_keys($totals), 0), ['day' => 0]));
            foreach ($totals as $k => $v) {
                if ($k !== 'day') {
                    $totals[$k] += $row[$k];
                }
            }
            $out[] = $row;
        }

        return [
            'columns' => $this->columns([
                'day' => ['Date', 'date'], 'total' => ['Inspections', 'int'], 'passed' => ['Passed', 'int'], 'failed' => ['Failed', 'int'],
                'pending' => ['Pending approval', 'int'], 'approved' => ['Approved', 'int'], 'rejected_reports' => ['Rejected reports', 'int'],
                'oos' => ['OOS readings', 'int'], 'rejected_qty' => ['Rejected qty', 'int'],
            ]),
            'rows'   => $out,
            'totals' => $totals,
        ];
    }

    /**
     * Machine-wise / part-wise summary.
     *
     * @param array<string, mixed>                  $f
     * @param array<string, array{0: string, 1: string}> $keys
     *
     * @return array<string, mixed>
     */
    private function grouped(array $f, string $groupBy, array $keys, string $table, string $on): array
    {
        $select = [];
        foreach (array_keys($keys) as $column) {
            $select[] = $column . ' AS ' . substr($column, strpos($column, '.') + 1);
        }
        $rows = $this->base($f)
            ->select(implode(', ', $select) . ", COUNT(*) AS total,
                      SUM(r.overall_result = 'PASS' AND r.status <> 'DRAFT') AS passed,
                      SUM(r.overall_result = 'FAIL' AND r.status <> 'DRAFT') AS failed,
                      SUM(r.oos_count) AS oos, SUM(r.status = 'REJECTED') AS rejected_reports, MAX(r.inspection_date) AS last_date", false)
            ->join($table, $on)
            ->groupBy($groupBy . ', ' . implode(', ', array_keys($keys)))
            ->orderBy('failed', 'DESC')->orderBy('total', 'DESC')
            ->get()->getResultArray();

        $columns = [];
        foreach ($keys as $column => [$label, $type]) {
            $columns[substr($column, strpos($column, '.') + 1)] = [$label, $type];
        }

        return [
            'columns' => $this->columns($columns + [
                'total' => ['Inspections', 'int'], 'passed' => ['Passed', 'int'], 'failed' => ['Failed', 'int'], 'pass_rate' => ['Pass rate', 'percent'],
                'oos' => ['OOS readings', 'int'], 'rejected_reports' => ['Rejected reports', 'int'], 'last_date' => ['Last inspection', 'date'],
            ]),
            'rows'   => array_map(fn (array $r): array => $r + ['pass_rate' => $this->rate((int) $r['passed'], (int) $r['passed'] + (int) $r['failed'])], $rows),
            'totals' => null,
        ];
    }

    /**
     * Per operator: header operator (Setup Change / Product Parameter) or the
     * operator who signed each In-Process round.
     *
     * @param array<string, mixed> $f
     *
     * @return array<string, mixed>
     */
    private function operators(array $f): array
    {
        $header = $this->base($f)
            ->select("e.id, e.employee_code, e.full_name, COUNT(*) AS reports,
                      SUM(r.overall_result = 'PASS' AND r.status <> 'DRAFT') AS passed,
                      SUM(r.overall_result = 'FAIL' AND r.status <> 'DRAFT') AS failed, SUM(r.oos_count) AS oos", false)
            ->join('employees e', 'e.id = r.operator_employee_id')
            ->groupBy('e.id, e.employee_code, e.full_name')->get()->getResultArray();

        $rounds = $this->base($f)
            ->select("e.id, e.employee_code, e.full_name, COUNT(*) AS rounds,
                      SUM((SELECT COUNT(*) FROM inspection_readings rd JOIN inspection_observations o ON o.id = rd.observation_id
                           WHERE o.round_id = ro.id AND rd.result = 'FAIL')) AS oos", false)
            ->join('inspection_rounds ro', 'ro.report_id = r.id')
            ->join('users u', 'u.id = ro.operator_user_id')
            ->join('employees e', 'e.id = u.employee_id')
            ->groupBy('e.id, e.employee_code, e.full_name')->get()->getResultArray();

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
            'columns' => $this->columns([
                'employee_code' => ['Employee ID', 'text'], 'full_name' => ['Operator', 'text'], 'reports' => ['Reports (header operator)', 'int'],
                'passed' => ['Passed', 'int'], 'failed' => ['Failed', 'int'], 'rounds' => ['In-Process inspections signed', 'int'], 'oos' => ['OOS readings', 'int'],
            ]),
            'rows'   => $rows,
            'totals' => null,
        ];
    }

    /**
     * @param array<string, mixed> $f
     *
     * @return array<string, mixed>
     */
    private function rejections(array $f, ?Paging $paging): array
    {
        $b = $this->base($f)
            ->select("r.id, r.inspection_date, r.report_no, r.revision_no, p.part_number, m.machine_code, s.code AS shift_code,
                      ro.round_no, ro.inspection_time, ro.lot_status, ro.accepted_qty, ro.rejected_qty, ro.rework_qty, ro.remarks", false)
            ->join('inspection_rounds ro', 'ro.report_id = r.id')
            ->join('parts p', 'p.id = r.part_id')
            ->join('machines m', 'm.id = r.machine_id')
            ->join('shifts s', 's.id = ro.shift_id', 'left')
            ->groupStart()->where('ro.rejected_qty >', 0)->orWhere('ro.rework_qty >', 0)->orWhereIn('ro.lot_status', ['REJECTED', 'REWORK', 'ON_HOLD'])
            ->orWhere('r.status', ReportStatus::Rejected->value)->groupEnd()
            ->orderBy('r.inspection_date')->orderBy('r.id')->orderBy('ro.round_no');
        $sum = $this->base($f)->select('COALESCE(SUM(ro.accepted_qty), 0) AS a, COALESCE(SUM(ro.rejected_qty), 0) AS rj, COALESCE(SUM(ro.rework_qty), 0) AS rw', false)
            ->join('inspection_rounds ro', 'ro.report_id = r.id')->get()->getRowArray();
        $rows = $this->page($b, $paging);
        foreach ($rows as &$row) {
            $row['lot_status'] = $row['lot_status'] === null ? '' : ucwords(strtolower(str_replace('_', ' ', $row['lot_status'])));
        }

        return [
            'columns' => $this->columns([
                'inspection_date' => ['Date', 'date'], 'report_no' => ['Report no.', 'text'], 'revision_no' => ['Rev', 'int'], 'part_number' => ['Part', 'text'],
                'machine_code' => ['Machine', 'text'], 'shift_code' => ['Shift', 'text'], 'round_no' => ['Insp. #', 'int'], 'inspection_time' => ['Time', 'text'],
                'lot_status' => ['Lot status', 'text'], 'accepted_qty' => ['Accepted', 'int'], 'rejected_qty' => ['Rejected', 'int'], 'rework_qty' => ['Rework', 'int'],
                'remarks' => ['Remarks', 'text'],
            ]),
            'rows'   => $rows,
            'totals' => ['inspection_date' => 'Period total (all inspections)', 'accepted_qty' => (int) $sum['a'], 'rejected_qty' => (int) $sum['rj'], 'rework_qty' => (int) $sum['rw'],
                'rejection_rate' => $this->rate((int) $sum['rj'], (int) $sum['a'] + (int) $sum['rj'] + (int) $sum['rw'])],
            'link'   => 'id',
        ];
    }

    /**
     * @param array<string, mixed> $f
     *
     * @return array<string, mixed>
     */
    private function oos(array $f, ?Paging $paging): array
    {
        $b = $this->base($f)
            ->select("r.id, r.inspection_date, r.report_no, r.revision_no, rt.code AS type_code, p.part_number, m.machine_code,
                      COALESCE(s.code, rs.code) AS shift_code, ro.round_no, tp.name AS parameter, tp.decimal_places, o.lsl, o.usl,
                      rd.reading_no, rd.value_numeric, rd.value_choice, rd.value_date, g.gauge_code, o.remarks, r.status", false)
            ->join('report_types rt', 'rt.id = r.report_type_id')
            ->join('inspection_observations o', 'o.report_id = r.id')
            ->join('inspection_readings rd', 'rd.observation_id = o.id')
            ->join('inspection_rounds ro', 'ro.id = o.round_id')
            ->join('template_parameters tp', 'tp.id = o.template_parameter_id')
            ->join('parts p', 'p.id = r.part_id')
            ->join('machines m', 'm.id = r.machine_id')
            ->join('shifts s', 's.id = r.shift_id', 'left')
            ->join('shifts rs', 'rs.id = ro.shift_id', 'left')
            ->join('gauges g', 'g.id = o.gauge_id', 'left')
            ->where('rd.result', 'FAIL')
            ->orderBy('r.inspection_date')->orderBy('r.id')->orderBy('ro.round_no')->orderBy('tp.sort_order')->orderBy('rd.reading_no');
        $rows = $this->page($b, $paging);
        foreach ($rows as &$row) {
            $places       = (int) $row['decimal_places'];
            $row['value'] = $row['value_numeric'] !== null ? qms_decimal($row['value_numeric'], $places)
                : ($row['value_choice'] !== null ? str_replace('_', ' ', $row['value_choice']) : (string) $row['value_date']);
            $row['lsl'] = $row['lsl'] === null ? '' : qms_decimal($row['lsl'], $places);
            $row['usl'] = $row['usl'] === null ? '' : qms_decimal($row['usl'], $places);
        }

        return [
            'columns' => $this->columns([
                'inspection_date' => ['Date', 'date'], 'report_no' => ['Report no.', 'text'], 'revision_no' => ['Rev', 'int'], 'type_code' => ['Type', 'text'],
                'part_number' => ['Part', 'text'], 'machine_code' => ['Machine', 'text'], 'shift_code' => ['Shift', 'text'], 'round_no' => ['Insp. #', 'int'],
                'parameter' => ['Parameter', 'text'], 'lsl' => ['LSL', 'text'], 'usl' => ['USL', 'text'], 'reading_no' => ['Obs.', 'int'],
                'value' => ['Value', 'text'], 'gauge_code' => ['Gauge ID', 'text'], 'remarks' => ['Remarks', 'text'], 'status' => ['Status', 'status'],
            ]),
            'rows'   => $rows,
            'totals' => null,
            'link'   => 'id',
        ];
    }

    /**
     * @param array<string, mixed> $f
     *
     * @return array<string, mixed>
     */
    private function gaugesDue(array $f): array
    {
        $limit = (new DateTimeImmutable($f['today']))->modify('+' . $f['days'] . ' days')->format('Y-m-d');
        $rows  = $this->db->table('gauges g')
            ->select('g.id, g.gauge_code, g.gauge_name, t.name AS type_name, g.location, g.last_calibration_date, g.calibration_due_date, g.status,
                      DATEDIFF(g.calibration_due_date, ' . $this->db->escape($f['today']) . ') AS days_left', false)
            ->join('gauge_types t', 't.id = g.gauge_type_id')
            ->where('t.requires_calibration', 1)->where('g.status !=', 'RETIRED')
            ->groupStart()->where('g.calibration_due_date IS NULL')->orWhere('g.calibration_due_date <=', $limit)->groupEnd()
            ->orderBy('g.calibration_due_date IS NULL', 'DESC', false)->orderBy('g.calibration_due_date')->get()->getResultArray();
        foreach ($rows as &$row) {
            $row['state'] = match (true) {
                $row['calibration_due_date'] === null => 'Never calibrated',
                (int) $row['days_left'] < 0           => 'EXPIRED',
                default                               => 'Due in ' . (int) $row['days_left'] . ' day(s)',
            };
        }

        return [
            'columns' => $this->columns([
                'gauge_code' => ['Gauge ID', 'text'], 'gauge_name' => ['Name', 'text'], 'type_name' => ['Type', 'text'], 'location' => ['Location', 'text'],
                'last_calibration_date' => ['Last calibration', 'date'], 'calibration_due_date' => ['Due date', 'date'], 'state' => ['Calibration', 'text'],
                'status' => ['Gauge status', 'text'],
            ]),
            'rows'   => $rows,
            'totals' => null,
            'link'   => 'gauge',
        ];
    }

    // ================================================================ helpers

    /**
     * @param array<string, mixed> $f
     */
    private function base(array $f): BaseBuilder
    {
        $b = $this->db->table('inspection_reports r')
            ->where('r.is_current', 1)->where('r.status !=', ReportStatus::Cancelled->value)
            ->where('r.inspection_date >=', $f['from'])->where('r.inspection_date <=', $f['to']);
        foreach (['type' => 'r.report_type_id', 'part' => 'r.part_id', 'machine' => 'r.machine_id', 'operator' => 'r.operator_employee_id'] as $key => $column) {
            if (($f[$key] ?? 0) > 0) {
                $b->where($column, $f[$key]);
            }
        }
        if (($f['shift'] ?? 0) > 0) {
            $shift = $f['shift'];
            $b->groupStart()->where('r.shift_id', $shift)
                ->orWhereIn('r.id', static fn (BaseBuilder $s): BaseBuilder => $s->select('report_id')->from('inspection_rounds')->where('shift_id', $shift))
                ->groupEnd();
        }
        if (($f['status'] ?? '') === 'PENDING') {
            $b->whereIn('r.status', ReportStatus::pendingValues());
        } elseif (($f['status'] ?? '') !== '') {
            $b->where('r.status', $f['status']);
        }

        return $b;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function page(BaseBuilder $b, ?Paging $paging): array
    {
        if ($paging === null) {
            return $b->limit(self::EXPORT_LIMIT)->get()->getResultArray();
        }
        $paging->total = (int) $b->countAllResults(false);

        return $b->limit($paging->perPage, $paging->offset())->get()->getResultArray();
    }

    /**
     * @param array<string, array{0: string, 1: string}> $columns
     *
     * @return array<string, array{label: string, type: string}>
     */
    private function columns(array $columns): array
    {
        return array_map(static fn (array $c): array => ['label' => $c[0], 'type' => $c[1]], $columns);
    }

    private function rate(int $part, int $whole): ?string
    {
        return $whole === 0 ? null : number_format($part * 100 / $whole, 1, '.', '');
    }
}
