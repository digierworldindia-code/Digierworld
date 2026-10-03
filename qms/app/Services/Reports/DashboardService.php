<?php

namespace App\Services\Reports;

use App\Enums\ReportStatus;
use App\Services\Settings\SettingsService;
use App\Services\Support\Clock;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/**
 * Management dashboard figures. Counts use the current revision of each report
 * (is_current = 1) and ignore cancelled reports; the approval workload counts
 * every report waiting for a signature, revisions included.
 */
class DashboardService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * Validated filters with the date range they cover.
     *
     * @param array<string, mixed> $input period (day|month), date, month, type, part, machine, shift, operator, status
     *
     * @return array<string, mixed>
     */
    public function filters(array $input, string $today): array
    {
        $period = ($input['period'] ?? '') === 'month' ? 'month' : 'day';
        $f      = ['period' => $period];
        if ($period === 'month') {
            $month = (string) ($input['month'] ?? '');
            $start = DateTimeImmutable::createFromFormat('!Y-m', $month) ?: new DateTimeImmutable(substr($today, 0, 7) . '-01');
            $f['month'] = $start->format('Y-m');
            $f['from']  = $start->format('Y-m-01');
            $f['to']    = $start->format('Y-m-t');
            $f['label'] = $start->format('F Y');
        } else {
            $date       = (string) ($input['date'] ?? '');
            $day        = DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: new DateTimeImmutable($today);
            $f['date']  = $day->format('Y-m-d');
            $f['from']  = $f['date'];
            $f['to']    = $f['date'];
            $f['label'] = plant_date($f['date']) . ($f['date'] === $today ? ' (today)' : '');
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
     * @return array<string, int>
     */
    public function cards(array $f): array
    {
        $submitted = static fn (BaseBuilder $b): BaseBuilder => $b->whereNotIn('r.status', [ReportStatus::Draft->value]);
        $oos       = $this->base($f)->select('COALESCE(SUM(r.oos_count), 0) AS readings, COALESCE(SUM(r.oos_count > 0), 0) AS reports', false)->get()->getRowArray();

        return [
            'total'       => $this->base($f)->countAllResults(),
            'passed'      => $submitted($this->base($f))->where('r.overall_result', 'PASS')->countAllResults(),
            'failed'      => $submitted($this->base($f))->where('r.overall_result', 'FAIL')->countAllResults(),
            'in_progress' => $this->base($f)->whereIn('r.status', [ReportStatus::Draft->value, ReportStatus::Returned->value])->countAllResults(),
            'pending'     => $this->base($f, false)->whereIn('r.status', ReportStatus::pendingValues())->countAllResults(),
            'oos'         => (int) $oos['readings'],
            'oos_reports' => (int) $oos['reports'],
        ];
    }

    /**
     * Pass / fail per production day: the 14 days up to the selected day, or every day of the month.
     *
     * @param array<string, mixed> $f
     *
     * @return list<array{date: string, pass: int, fail: int, other: int}>
     */
    public function trend(array $f): array
    {
        $to   = new DateTimeImmutable($f['to']);
        $from = $f['period'] === 'month' ? new DateTimeImmutable($f['from']) : $to->modify('-13 days');
        $g    = $f;
        $g['from'] = $from->format('Y-m-d');

        $rows = $this->base($g)->select("r.inspection_date AS d, SUM(r.overall_result = 'PASS' AND r.status <> 'DRAFT') AS pass,
                    SUM(r.overall_result = 'FAIL' AND r.status <> 'DRAFT') AS fail, COUNT(*) AS total", false)
            ->groupBy('r.inspection_date')->get()->getResultArray();
        $byDate = array_column($rows, null, 'd');

        $out = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $key   = $day->format('Y-m-d');
            $row   = $byDate[$key] ?? ['pass' => 0, 'fail' => 0, 'total' => 0];
            $out[] = ['date' => $key, 'pass' => (int) $row['pass'], 'fail' => (int) $row['fail'], 'other' => (int) $row['total'] - (int) $row['pass'] - (int) $row['fail']];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $f
     *
     * @return list<array<string, mixed>>
     */
    public function byType(array $f): array
    {
        return $this->base($f)
            ->select("rt.code, rt.name, COUNT(*) AS total, SUM(r.status IN ('DRAFT','RETURNED')) AS in_progress,
                      SUM(r.status IN ('PENDING_PRODUCTION','PENDING_QUALITY','PENDING_QA')) AS pending,
                      SUM(r.status = 'QA_APPROVED') AS approved, SUM(r.status = 'REJECTED') AS rejected,
                      SUM(r.overall_result = 'FAIL') AS failed", false)
            ->join('report_types rt', 'rt.id = r.report_type_id')
            ->groupBy('rt.id, rt.code, rt.name')->orderBy('rt.id')->get()->getResultArray();
    }

    /**
     * Parameters with the most out-of-specification readings.
     *
     * @param array<string, mixed> $f
     *
     * @return list<array<string, mixed>>
     */
    public function topOos(array $f, int $limit = 8): array
    {
        return $this->base($f)
            ->select('ip.code, ip.name, COUNT(*) AS readings, COUNT(DISTINCT r.id) AS reports', false)
            ->join('inspection_observations o', 'o.report_id = r.id')
            ->join('inspection_readings rd', 'rd.observation_id = o.id')
            ->join('template_parameters tp', 'tp.id = o.template_parameter_id')
            ->join('inspection_parameters ip', 'ip.id = tp.parameter_id')
            ->where('rd.result', 'FAIL')
            ->groupBy('ip.id, ip.code, ip.name')->orderBy('readings', 'DESC')->limit($limit)
            ->get()->getResultArray();
    }

    /**
     * @param array<string, mixed> $f
     *
     * @return list<array<string, mixed>>
     */
    public function machines(array $f, int $limit = 10): array
    {
        return $this->base($f)
            ->select("m.machine_code, m.machine_name, COUNT(*) AS total, SUM(r.overall_result = 'FAIL') AS failed, SUM(r.oos_count) AS oos", false)
            ->join('machines m', 'm.id = r.machine_id')
            ->groupBy('m.id, m.machine_code, m.machine_name')->orderBy('failed', 'DESC')->orderBy('total', 'DESC')->limit($limit)
            ->get()->getResultArray();
    }

    /**
     * Reports waiting for a signature for more than $hours (any date).
     *
     * @return list<array<string, mixed>>
     */
    public function overdue(int $hours = 24, int $limit = 10): array
    {
        return $this->db->table('inspection_reports r')
            ->select('r.id, r.report_no, r.revision_no, r.status, r.submitted_at, rt.code AS type_code, p.part_number, m.machine_code')
            ->join('report_types rt', 'rt.id = r.report_type_id')
            ->join('parts p', 'p.id = r.part_id')
            ->join('machines m', 'm.id = r.machine_id')
            ->whereIn('r.status', ReportStatus::pendingValues())
            ->where('r.submitted_at <', $this->clock->nowUtc()->modify("-{$hours} hours")->format('Y-m-d H:i:s'))
            ->orderBy('r.submitted_at')->limit($limit)->get()->getResultArray();
    }

    /**
     * @return array{expired: int, soon: int}
     */
    public function gaugeAlerts(string $today): array
    {
        $warn = max(0, $this->settings->int('gauge.due_warning_days', 7));
        $soon = (new DateTimeImmutable($today))->modify("+{$warn} days")->format('Y-m-d');
        $base = fn (): BaseBuilder => $this->db->table('gauges g')->join('gauge_types t', 't.id = g.gauge_type_id')
            ->where('g.status', 'ACTIVE')->where('t.requires_calibration', 1);

        return [
            'expired' => $base()->groupStart()->where('g.calibration_due_date <', $today)->orWhere('g.calibration_due_date IS NULL')->groupEnd()->countAllResults(),
            'soon'    => $base()->where('g.calibration_due_date >=', $today)->where('g.calibration_due_date <=', $soon)->countAllResults(),
        ];
    }

    /**
     * @param array<string, mixed> $f
     */
    private function base(array $f, bool $currentOnly = true): BaseBuilder
    {
        $b = $this->db->table('inspection_reports r')
            ->where('r.status !=', ReportStatus::Cancelled->value)
            ->where('r.inspection_date >=', $f['from'])->where('r.inspection_date <=', $f['to']);
        if ($currentOnly) {
            $b->where('r.is_current', 1);
        }
        foreach (['type' => 'r.report_type_id', 'part' => 'r.part_id', 'machine' => 'r.machine_id'] as $key => $column) {
            if ($f[$key] > 0) {
                $b->where($column, $f[$key]);
            }
        }
        if ($f['shift'] > 0) {
            $shift = $f['shift'];
            $b->groupStart()->where('r.shift_id', $shift)
                ->orWhereIn('r.id', static fn (BaseBuilder $s): BaseBuilder => $s->select('report_id')->from('inspection_rounds')->where('shift_id', $shift))
                ->groupEnd();
        }
        if ($f['operator'] > 0) {
            $employee = $f['operator'];
            $b->groupStart()->where('r.operator_employee_id', $employee)
                ->orWhereIn('r.id', static fn (BaseBuilder $s): BaseBuilder => $s->select('ro.report_id')->from('inspection_rounds ro')
                    ->join('users u', 'u.id = ro.operator_user_id')->where('u.employee_id', $employee))
                ->groupEnd();
        }
        if ($f['status'] === 'PENDING') {
            $b->whereIn('r.status', ReportStatus::pendingValues());
        } elseif ($f['status'] !== '') {
            $b->where('r.status', $f['status']);
        }

        return $b;
    }
}
