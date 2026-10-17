<?php

namespace App\Controllers\Hr;

use App\Exceptions\BusinessRuleException;

/** Leave requests, approvals and balances (HR view). */
class LeavesController extends HrBase
{
    public function index()
    {
        $this->authorize(['hr.leave.view_all', 'hr.leave.approve']);
        $f = [
            'status' => (string) ($this->request->getGet('status') ?? 'pending'), 'employee' => (int) $this->request->getGet('employee'),
            'type' => (int) $this->request->getGet('type'), 'from' => ymd((string) $this->request->getGet('from')), 'to' => ymd((string) $this->request->getGet('to')),
        ];
        [$page, $perPage, $offset] = $this->paging(50);
        $b = $this->db->table('leave_requests r')->join('employees e', 'e.id = r.employee_id')->join('leave_types t', 't.id = r.leave_type_id')
            ->join('user_profiles p', 'p.user_id = r.decided_by', 'left');
        if (in_array($f['status'], ['pending', 'approved', 'rejected', 'cancelled'], true)) {
            $b->where('r.status', $f['status']);
        }
        if ($f['employee']) {
            $b->where('r.employee_id', $f['employee']);
        }
        if ($f['type']) {
            $b->where('r.leave_type_id', $f['type']);
        }
        if ($f['from']) {
            $b->where('r.to_date >=', $f['from']);
        }
        if ($f['to']) {
            $b->where('r.from_date <=', $f['to']);
        }
        $total = (clone $b)->countAllResults();
        $rows  = $b->select('r.*, e.full_name, e.employee_code, e.user_id AS emp_user_id, t.code, t.name AS type_name, t.is_paid, p.full_name AS decided_name')
            ->orderBy("r.status = 'pending'", 'DESC', false)->orderBy('r.from_date', 'DESC')->limit($perPage, $offset)->get()->getResultArray();

        return $this->render('hr/leaves/index', [
            'pageTitle' => 'Leave requests', 'breadcrumbs' => [['HR'], ['Leaves']], 'rows' => $rows, 'f' => $f,
            'types' => service('leaves')->types(), 'employees' => service('employees')->picker(), 'total' => $total, 'page' => $page, 'perPage' => $perPage,
            'pendingCount' => $this->db->table('leave_requests')->where('status', 'pending')->countAllResults(),
        ]);
    }

    /** HR records a leave on an employee's behalf (still goes through approval). */
    public function create()
    {
        $this->authorize('hr.leave.approve');
        $emp = $this->employee((int) $this->request->getPost('employee_id'));
        try {
            $id = service('leaves')->request((int) $emp['id'], $this->request->getPost());
            if ($this->request->getPost('approve_now') && (int) $emp['user_id'] !== (int) $this->userId) {
                service('leaves')->decide($id, 'approved', 'Recorded and approved by HR');
            }
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/leaves?employee=' . $emp['id'] . '&status=all')->with('success', 'Leave recorded.');
    }

    public function decide($id)
    {
        $this->authorize('hr.leave.approve');
        $decision = $this->request->getPost('decision') === 'approved' ? 'approved' : 'rejected';
        try {
            service('leaves')->decide($this->idOr404($id), $decision, trim((string) $this->request->getPost('decision_note')));
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->back()->with('success', 'Leave ' . $decision . '.');
    }

    public function cancel($id)
    {
        $this->authorize('hr.leave.approve');
        try {
            service('leaves')->cancel($this->idOr404($id), trim((string) $this->request->getPost('reason')));
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->back()->with('success', 'Leave cancelled and balance restored.');
    }

    public function balances()
    {
        $this->authorize('hr.leave.view_all');
        $types = service('leaves')->types();
        $out   = [];
        foreach (service('employees')->picker() as $e) {
            $out[] = ['emp' => $e, 'bal' => array_column(service('leaves')->balances((int) $e['id']), null, 'leave_type_id')];
        }

        return $this->render('hr/leaves/balances', [
            'pageTitle' => 'Leave balances — FY ' . service('fy')->current()['code'], 'breadcrumbs' => [['HR'], ['Leaves', 'hr/leaves'], ['Balances']],
            'types' => $types, 'rows' => $out,
        ]);
    }

    public function adjust()
    {
        $this->authorize('hr.leave.manage_types');
        try {
            service('leaves')->adjust((int) $this->request->getPost('employee_id'), (int) $this->request->getPost('leave_type_id'), trim((string) $this->request->getPost('days')), trim((string) $this->request->getPost('reason')));
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/leaves/balances')->with('success', 'Balance adjusted.');
    }
}
