<?php

namespace App\Controllers\Hr;

use App\Exceptions\BusinessRuleException;
use App\Services\Hr\EmployeeService;

/**
 * Employee directory and 360° profile. Each section of the profile is
 * gated separately: basic directory, full profile, salary, bank details,
 * private documents, payroll and incentives.
 */
class EmployeesController extends HrBase
{
    public function index()
    {
        $this->authorize(['hr.employee.view_basic', 'hr.employee.view_all']);
        $f = [
            'q' => trim((string) $this->request->getGet('q')), 'department' => (int) $this->request->getGet('department'),
            'status' => (string) ($this->request->getGet('status') ?? 'current'),
        ];
        [$page, $perPage, $offset] = $this->paging(50);
        $b = $this->db->table('employees e')->join('departments d', 'd.id = e.department_id', 'left')->join('designations g', 'g.id = e.designation_id', 'left')
            ->join('employees m', 'm.id = e.reporting_manager_id', 'left');
        if ($f['q'] !== '') {
            $b->groupStart()->like('e.full_name', $f['q'])->orLike('e.employee_code', $f['q'])->orLike('e.work_email', $f['q'])->orLike('e.work_phone', $f['q'])->groupEnd();
        }
        if ($f['department']) {
            $b->where('e.department_id', $f['department']);
        }
        if ($f['status'] === 'current') {
            $b->where('e.employment_status !=', 'exited');
        } elseif (in_array($f['status'], ['probation', 'active', 'notice', 'exited'], true)) {
            $b->where('e.employment_status', $f['status']);
        }
        $total = (clone $b)->countAllResults();
        $rows  = $b->select('e.id, e.employee_code, e.full_name, e.work_phone, e.work_email, e.employment_status, e.date_of_joining, e.work_location, d.name AS department, g.name AS designation, m.full_name AS manager')
            ->orderBy('e.full_name')->limit($perPage, $offset)->get()->getResultArray();
        if (! $this->can('hr.employee.view_all')) {
            foreach ($rows as &$r) {
                unset($r['date_of_joining']);
            }
        }

        return $this->render('hr/employees/index', [
            'pageTitle' => 'Employees', 'breadcrumbs' => [['HR'], ['Employees']], 'rows' => $rows, 'f' => $f,
            'departments' => $this->departments(), 'total' => $total, 'page' => $page, 'perPage' => $perPage,
        ]);
    }

    public function new()
    {
        $this->authorize('hr.employee.manage');

        return $this->form(['employment_status' => 'active', 'employment_type' => 'full_time', 'attendance_mode' => 'manual', 'date_of_joining' => date('Y-m-d')]);
    }

    public function create()
    {
        $this->authorize('hr.employee.manage');
        try {
            $id = service('employees')->create($this->request->getPost());
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/employees/' . $id)->with('success', 'Employee created. Add salary and bank details from the profile if you have access.');
    }

    public function edit($id)
    {
        $this->authorize('hr.employee.manage');

        return $this->form($this->employee($this->idOr404($id)));
    }

    public function update($id)
    {
        $this->authorize('hr.employee.manage');
        $emp = $this->employee($this->idOr404($id));
        try {
            service('employees')->update((int) $emp['id'], $this->request->getPost());
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/employees/' . $emp['id'])->with('success', 'Employee updated.');
    }

    private function form(array $emp): string
    {
        $linked = array_map('intval', array_column($this->db->table('employees')->select('user_id')->where('user_id IS NOT NULL', null, false)->where('id !=', (int) ($emp['id'] ?? 0))->get()->getResultArray(), 'user_id'));

        return $this->render('hr/employees/form', [
            'pageTitle' => isset($emp['id']) ? 'Edit ' . $emp['full_name'] : 'New employee',
            'breadcrumbs' => [['HR'], ['Employees', 'hr/employees'], [isset($emp['id']) ? $emp['employee_code'] : 'New']],
            'emp' => $emp, 'departments' => $this->departments(), 'designations' => $this->designations(),
            'managers' => array_filter(service('employees')->picker(), static fn ($m) => (int) $m['id'] !== (int) ($emp['id'] ?? 0)),
            'users' => array_diff_key($this->users(), array_flip($linked)), 'nextCode' => isset($emp['id']) ? null : service('employees')->nextCode(),
        ]);
    }

    public function show($id)
    {
        $this->authorize(['hr.employee.view_basic', 'hr.employee.view_all']);
        $emp  = $this->employee($this->idOr404($id));
        $full = $this->can('hr.employee.view_all');
        if (! $full) {
            $emp = array_intersect_key($emp, array_flip(EmployeeService::BASIC_FIELDS));
        }
        $data = [
            'pageTitle' => $emp['full_name'], 'breadcrumbs' => [['HR'], ['Employees', 'hr/employees'], [$emp['employee_code']]],
            'emp' => $emp, 'full' => $full,
            'department' => $this->db->table('departments')->select('name')->where('id', $emp['department_id'])->get()->getRow()->name ?? null,
            'designation' => $this->db->table('designations')->select('name')->where('id', $emp['designation_id'])->get()->getRow()->name ?? null,
            'manager' => $emp['reporting_manager_id'] ? service('employees')->find((int) $emp['reporting_manager_id']) : null,
            'login' => $emp['user_id'] ? $this->db->table('user_profiles')->select('full_name')->where('user_id', $emp['user_id'])->get()->getRow()->full_name ?? null : null,
        ];
        if ($full) {
            $month = $this->monthParam();
            $data['month']      = $month;
            $data['attendance'] = service('attendance')->summary(service('employees')->find((int) $emp['id']), $month . '-01', min(date('Y-m-t', strtotime($month . '-01')), date('Y-m-d')));
            $data['balances']   = service('leaves')->balances((int) $emp['id']);
            $data['leaves']     = $this->db->table('leave_requests r')->select('r.*, t.code, t.name AS type_name')->join('leave_types t', 't.id = r.leave_type_id')
                ->where('r.employee_id', $emp['id'])->orderBy('r.from_date', 'DESC')->limit(10)->get()->getResultArray();
            $data['reports']    = $this->db->table('employees')->select('id, employee_code, full_name')->where('reporting_manager_id', $emp['id'])->where('employment_status !=', 'exited')->get()->getResultArray();
        }
        if ($this->can('hr.salary.view')) {
            $data['structures'] = service('salaries')->structures((int) $emp['id']);
        }
        if ($this->can('hr.employee.bank_view')) {
            $data['bank'] = service('employees')->bank((int) $emp['id']);
        }
        if ($this->can('hr.employee.private_docs')) {
            $data['docs'] = $this->db->table('employee_documents d')->select('d.*, f.original_name, f.size_bytes')->join('stored_files f', 'f.id = d.stored_file_id')
                ->where('d.employee_id', $emp['id'])->orderBy('d.created_at', 'DESC')->get()->getResultArray();
        }
        if ($this->can('hr.payroll.view')) {
            $data['payroll'] = $this->db->table('payroll_items i')->select('i.id, i.payable_days, i.gross_pay, i.total_deductions, i.net_pay, i.amount_paid, i.payment_status, r.id AS run_id, r.period, r.status AS run_status')
                ->join('payroll_runs r', 'r.id = i.payroll_run_id')->where('i.employee_id', $emp['id'])->orderBy('r.period', 'DESC')->limit(12)->get()->getResultArray();
        }
        if ($this->can('hr.incentive.view_all')) {
            $data['incentives'] = $this->db->table('incentive_entries')->where('employee_id', $emp['id'])->orderBy('id', 'DESC')->limit(10)->get()->getResultArray();
        }
        $cand = (int) (service('employees')->find((int) $emp['id'])['candidate_id'] ?? 0);
        if ($cand && service('candidateAccess')->canViewCandidateId($cand)) {
            $data['candidate'] = $this->db->table('candidates')->select('id, candidate_code, application_date')->where('id', $cand)->get()->getRowArray();
            $data['timeline']  = $this->db->table('recruitment_events e')->select('e.*, p.full_name AS actor')->join('user_profiles p', 'p.user_id = e.actor_id', 'left')
                ->where('e.candidate_id', $cand)->orderBy('e.occurred_at')->get()->getResultArray();
        }

        return $this->render('hr/employees/show', $data);
    }

    public function saveBank($id)
    {
        $this->authorize('hr.employee.bank_view');
        $this->authorize('hr.employee.manage');
        $emp = $this->employee($this->idOr404($id));
        try {
            service('employees')->saveBank((int) $emp['id'], $this->request->getPost());
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/employees/' . $emp['id'] . '#bank')->with('success', 'Bank details saved (account number stored encrypted).');
    }

    public function revealBank($id)
    {
        $this->authorize('hr.employee.bank_view');
        $emp = $this->employee($this->idOr404($id));

        return $this->json(['value' => service('employees')->revealAccount((int) $emp['id'])]);
    }

    public function uploadDocument($id)
    {
        $this->authorize('hr.employee.private_docs');
        $emp  = $this->employee($this->idOr404($id));
        $file = $this->request->getFile('document');
        if ($file === null) {
            return redirect()->back()->with('error', 'Choose a file to upload.');
        }
        try {
            service('employees')->addDocument((int) $emp['id'], $file, (string) $this->request->getPost('doc_kind'), trim((string) $this->request->getPost('label')));
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/employees/' . $emp['id'] . '#docs')->with('success', 'Document stored privately.');
    }

    public function saveSalary($id)
    {
        $this->authorize('hr.salary.manage');
        $emp = $this->employee($this->idOr404($id));
        try {
            service('salaries')->save((int) $emp['id'], $this->request->getPost());
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/employees/' . $emp['id'] . '#salary')->with('success', 'Salary structure saved.');
    }
}
