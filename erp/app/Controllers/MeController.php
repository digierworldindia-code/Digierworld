<?php

namespace App\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Services\Hr\HrCalendar;

/**
 * Employee self-service. Every query is pinned to the employee linked to
 * the logged-in user; there is no id parameter to tamper with.
 */
class MeController extends BaseController
{
    /** @return array<string, mixed>|null */
    private function me(): ?array
    {
        return $this->userId ? service('employees')->forUser($this->userId) : null;
    }

    private function unlinked(): string
    {
        return $this->render('me/unlinked', ['pageTitle' => 'My Workspace', 'breadcrumbs' => [['My Workspace']]]);
    }

    public function index()
    {
        $this->authorize(['self.profile.view', 'self.attendance.view', 'self.leave.request', 'self.payslip.view', 'self.incentive.view']);
        $me = $this->me();
        if ($me === null) {
            return $this->unlinked();
        }
        $today = date('Y-m-d');

        return $this->render('me/index', [
            'pageTitle' => 'My Workspace', 'breadcrumbs' => [['My Workspace']], 'me' => $me,
            'department' => $this->db->table('departments')->select('name')->where('id', $me['department_id'])->get()->getRow()->name ?? null,
            'designation' => $this->db->table('designations')->select('name')->where('id', $me['designation_id'])->get()->getRow()->name ?? null,
            'manager' => $me['reporting_manager_id'] ? service('employees')->find((int) $me['reporting_manager_id'])['full_name'] ?? null : null,
            'todayMark' => $this->db->table('attendance')->where(['employee_id' => $me['id'], 'att_date' => $today])->get()->getRowArray(),
            'dayKind' => service('hrCalendar')->dayKind($today),
            'balances' => $this->can('self.leave.request') ? service('leaves')->balances((int) $me['id']) : [],
            'month' => $this->can('self.attendance.view') ? service('attendance')->summary($me, date('Y-m-01'), $today) : null,
            'payslips' => $this->can('self.payslip.view') ? $this->payslipRows((int) $me['id'], 3) : [],
            'tasks' => $this->db->table('tasks')->where('assigned_to', $this->userId)->whereIn('status', ['pending', 'in_progress'])->countAllResults(),
            'canPunch' => $me['attendance_mode'] === 'self_checkin' && $this->can('self.attendance.checkin'),
        ]);
    }

    public function punch()
    {
        $me = $this->me() ?? $this->deny();
        try {
            $r = service('attendance')->selfPunch($me);
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('me')->with('success', $r === 'checked_in' ? 'Checked in at ' . date('h:i A') . '.' : 'Checked out at ' . date('h:i A') . '.');
    }

    public function attendance()
    {
        $this->authorize('self.attendance.view');
        $me = $this->me();
        if ($me === null) {
            return $this->unlinked();
        }
        $m     = (string) $this->request->getGet('month');
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : date('Y-m');
        $from  = $month . '-01';
        $to    = date('Y-m-t', strtotime($from));
        $marks = array_column($this->db->table('attendance')->where('employee_id', $me['id'])->where('att_date >=', $from)->where('att_date <=', $to)->get()->getResultArray(), null, 'att_date');
        $cal   = service('hrCalendar');
        $days  = [];
        foreach (HrCalendar::dates($from, $to) as $d) {
            $days[] = ['date' => $d, 'mark' => $marks[$d] ?? null, 'kind' => $cal->dayKind($d), 'holiday' => $cal->holiday($d)];
        }

        return $this->render('me/attendance', [
            'pageTitle' => 'My attendance — ' . date('F Y', strtotime($from)), 'breadcrumbs' => [['My Workspace', 'me'], ['Attendance']],
            'month' => $month, 'days' => $days, 'summary' => service('attendance')->summary($me, $from, min($to, date('Y-m-d'))),
        ]);
    }

    public function leaves()
    {
        $this->authorize('self.leave.request');
        $me = $this->me();
        if ($me === null) {
            return $this->unlinked();
        }

        return $this->render('me/leaves', [
            'pageTitle' => 'My leave', 'breadcrumbs' => [['My Workspace', 'me'], ['Leave']],
            'balances' => service('leaves')->balances((int) $me['id']), 'types' => service('leaves')->types(),
            'requests' => $this->db->table('leave_requests r')->select('r.*, t.code, t.name AS type_name')->join('leave_types t', 't.id = r.leave_type_id')
                ->where('r.employee_id', $me['id'])->orderBy('r.from_date', 'DESC')->limit(50)->get()->getResultArray(),
            'holidays' => $this->db->table('holidays')->where('holiday_date >=', date('Y-m-d'))->where('is_optional', 0)->orderBy('holiday_date')->limit(8)->get()->getResultArray(),
        ]);
    }

    public function requestLeave()
    {
        $this->authorize('self.leave.request');
        $me = $this->me() ?? $this->deny();
        try {
            service('leaves')->request((int) $me['id'], $this->request->getPost());
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('me/leaves')->with('success', 'Leave requested. Your manager/HR has been notified.');
    }

    public function cancelLeave($id)
    {
        $this->authorize('self.leave.request');
        $me  = $this->me() ?? $this->deny();
        $req = $this->db->table('leave_requests')->where(['id' => $this->idOr404($id), 'employee_id' => $me['id']])->get()->getRowArray() ?? $this->notFound();
        if ($req['status'] !== 'pending') {
            return redirect()->back()->with('error', 'Only pending requests can be withdrawn. Ask HR to cancel an approved leave.');
        }
        try {
            service('leaves')->cancel((int) $req['id']);
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('me/leaves')->with('success', 'Leave request withdrawn.');
    }

    /** @return list<array<string, mixed>> own payslips from finalized runs only */
    private function payslipRows(int $employeeId, int $limit = 24): array
    {
        return $this->db->table('payroll_items i')->select('i.id, i.net_pay, i.gross_pay, i.total_deductions, i.payment_status, r.period, ps.stored_file_id, ps.version')
            ->join('payroll_runs r', 'r.id = i.payroll_run_id')
            ->join('payslips ps', 'ps.payroll_item_id = i.id AND ps.version = (SELECT MAX(version) FROM payslips WHERE payroll_item_id = i.id)', 'left', false)
            ->where('i.employee_id', $employeeId)->where('r.is_locked', 1)->orderBy('r.period', 'DESC')->limit($limit)->get()->getResultArray();
    }

    public function payslips()
    {
        $this->authorize('self.payslip.view');
        $me = $this->me();
        if ($me === null) {
            return $this->unlinked();
        }

        return $this->render('me/payslips', ['pageTitle' => 'My payslips', 'breadcrumbs' => [['My Workspace', 'me'], ['Payslips']], 'rows' => $this->payslipRows((int) $me['id'])]);
    }

    public function incentives()
    {
        $this->authorize('self.incentive.view');
        $me = $this->me();
        if ($me === null) {
            return $this->unlinked();
        }

        return $this->render('me/incentives', [
            'pageTitle' => 'My incentives', 'breadcrumbs' => [['My Workspace', 'me'], ['Incentives']],
            'rows' => $this->db->table('incentive_entries ie')->select('ie.id, ie.period, ie.source_type, ie.basis, ie.basis_amount, ie.share_percent, ie.amount, ie.status, ie.rejection_reason, ie.created_at, p.name AS policy, l.company_name')
                ->join('incentive_policies p', 'p.id = ie.policy_id')->join('leads l', 'l.id = ie.lead_id', 'left')
                ->where('ie.employee_id', $me['id'])->orderBy('ie.id', 'DESC')->limit(100)->get()->getResultArray(),
        ]);
    }
}
