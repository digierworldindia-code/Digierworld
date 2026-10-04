<?php

namespace App\Services\Inspections;

use App\Enums\ReportStatus;
use App\Libraries\Paging;
use App\Services\Access\AuthorizationService;
use App\Core\QueryBuilder;
use App\Core\Database;

/**
 * Read-only lists of inspection reports (search page, operator home).
 * Users holding only inspection.view_own see the reports they created,
 * submitted or inspected rounds on.
 */
class InspectionListService
{
    public const RESULTS = ['PASS', 'FAIL', 'INCOMPLETE'];

    public function __construct(
        private readonly Database $db,
        private readonly AuthorizationService $authz,
        private readonly ProductionCalendar $calendar,
    ) {
    }

    /**
     * @param array<string, mixed> $filters from, to, type, part, machine, shift, status, result, q, superseded
     * @param array<string, mixed> $user
     *
     * @return list<array<string, mixed>>
     */
    public function search(array $filters, Paging $paging, array $user): array
    {
        $builder = $this->base();
        $this->restrict($builder, $user);

        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            $date = (string) ($filters[$key] ?? '');
            if ($date !== '' && \DateTimeImmutable::createFromFormat('!Y-m-d', $date) !== false) {
                $builder->where('r.inspection_date ' . $op, $date);
            }
        }
        foreach (['type' => 'r.report_type_id', 'part' => 'r.part_id', 'machine' => 'r.machine_id', 'operator' => 'r.operator_employee_id'] as $key => $column) {
            if ((int) ($filters[$key] ?? 0) > 0) {
                $builder->where($column, (int) $filters[$key]);
            }
        }
        if ((int) ($filters['shift'] ?? 0) > 0) {
            $shiftId = (int) $filters['shift'];
            $builder->groupStart()->where('r.shift_id', $shiftId)
                ->orWhereIn('r.id', static fn (QueryBuilder $sub): QueryBuilder => $sub->select('report_id')->from('inspection_rounds')->where('shift_id', $shiftId))
                ->groupEnd();
        }
        $status = (string) ($filters['status'] ?? '');
        if ($status === 'PENDING') {
            $builder->whereIn('r.status', ReportStatus::pendingValues());
        } elseif (ReportStatus::tryFrom($status) !== null) {
            $builder->where('r.status', $status);
        } elseif (empty($filters['superseded'])) {
            $builder->where('r.status !=', ReportStatus::Superseded->value);
        }
        if (in_array($filters['result'] ?? '', self::RESULTS, true)) {
            $builder->where('r.overall_result', $filters['result']);
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $builder->groupStart()->like('r.report_no', $q)->orLike('p.part_number', $q)->orLike('p.part_name', $q)->orLike('m.machine_code', $q)->groupEnd();
        }

        $paging->total = (int) $builder->countAllResults(false);

        return $builder->orderBy('r.inspection_date', 'DESC')->orderBy('r.id', 'DESC')
            ->limit($paging->perPage, $paging->offset())->get()->getResultArray();
    }

    /**
     * Operator landing page lists.
     *
     * @param array<string, mixed> $user
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function home(array $user): array
    {
        $uid   = (int) $user['id'];
        $dates = $this->calendar->allowedDates();

        return [
            'returned' => $this->base()->where('r.status', ReportStatus::Returned->value)
                ->groupStart()->where('r.created_by', $uid)->orWhere('r.submitted_by', $uid)->groupEnd()
                ->orderBy('r.updated_at', 'DESC')->limit(20)->get()->getResultArray(),
            'drafts' => $this->base()->where('r.status', ReportStatus::Draft->value)->where('r.created_by', $uid)
                ->where('rt.layout !=', 'SHIFT_GRID')->orderBy('r.updated_at', 'DESC')->limit(20)->get()->getResultArray(),
            'sheets' => $this->base()->where('r.status', ReportStatus::Draft->value)->where('rt.layout', 'SHIFT_GRID')
                ->where('r.inspection_date >=', end($dates))->orderBy('r.inspection_date', 'DESC')->orderBy('m.machine_code')
                ->limit(30)->get()->getResultArray(),
            'recent' => $this->base()->where('r.submitted_by', $uid)->whereNotIn('r.status', [ReportStatus::Draft->value])
                ->orderBy('r.submitted_at', 'DESC')->limit(10)->get()->getResultArray(),
        ];
    }

    private function base(): QueryBuilder
    {
        return $this->db->table('inspection_reports r')
            ->select('r.id, r.report_no, r.revision_no, r.is_current, r.status, r.overall_result, r.oos_count, r.inspection_date,
                      r.created_at, r.created_by, r.submitted_at, r.submitted_by, r.updated_at,
                      rt.code AS type_code, rt.name AS type_name, rt.layout,
                      p.part_number, p.part_name, m.machine_code, m.machine_name, s.code AS shift_code,
                      op.full_name AS operator_name, cu.username AS created_by_name,
                      (SELECT COUNT(*) FROM inspection_rounds ro WHERE ro.report_id = r.id) AS round_count')
            ->join('report_types rt', 'rt.id = r.report_type_id')
            ->join('parts p', 'p.id = r.part_id')
            ->join('machines m', 'm.id = r.machine_id')
            ->join('shifts s', 's.id = r.shift_id', 'left')
            ->join('employees op', 'op.id = r.operator_employee_id', 'left')
            ->join('users cu', 'cu.id = r.created_by', 'left');
    }

    /**
     * @param array<string, mixed> $user
     */
    private function restrict(QueryBuilder $builder, array $user): void
    {
        if ($this->authz->userCan($user, 'inspection.view_all')) {
            return;
        }
        $uid = (int) $user['id'];
        $builder->groupStart()
            ->where('r.created_by', $uid)
            ->orWhere('r.submitted_by', $uid)
            ->orWhereIn('r.id', static fn (QueryBuilder $sub): QueryBuilder => $sub->select('report_id')->from('inspection_rounds')
                ->groupStart()->where('operator_user_id', $uid)->orWhere('created_by', $uid)->groupEnd())
            ->groupEnd();
    }
}
