<?php

namespace App\Controllers\Hr;

use App\Exceptions\BusinessRuleException;

/** Payroll runs, lines, payments, corrections, register, payslips, statutory settings and advances. */
class PayrollController extends HrBase
{
    private function runOr404(int $id): array
    {
        return $this->db->table('payroll_runs')->where('id', $id)->get()->getRowArray() ?? $this->notFound();
    }

    /** @return list<array<string, mixed>> */
    private function accounts(): array
    {
        return $this->db->table('bank_cash_accounts')->select('id, display_name, account_kind')->where('is_active', 1)->orderBy('display_name')->get()->getResultArray();
    }

    private function wrap(callable $fn, string $ok, ?string $to = null)
    {
        try {
            $fn();
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return ($to ? redirect()->to($to) : redirect()->back())->with('success', $ok);
    }

    public function index()
    {
        $this->authorize('hr.payroll.view');

        return $this->render('hr/payroll/index', [
            'pageTitle' => 'Payroll', 'breadcrumbs' => [['HR'], ['Payroll']],
            'runs' => $this->db->table('payroll_runs r')->select('r.*, (SELECT COUNT(*) FROM payroll_items i WHERE i.payroll_run_id = r.id) AS headcount', false)->orderBy('r.period', 'DESC')->get()->getResultArray(),
            'statutory' => service('salaries')->statutory(),
            'advances' => $this->db->table('employee_advances a')->select('a.*, e.full_name, e.employee_code')->join('employees e', 'e.id = a.employee_id')->where('a.status', 'open')->orderBy('a.advance_date', 'DESC')->get()->getResultArray(),
            'employees' => service('employees')->picker(), 'accounts' => $this->accounts(),
        ]);
    }

    public function create()
    {
        $this->authorize('hr.payroll.calculate');
        try {
            $id = service('payroll')->createRun((string) $this->request->getPost('period'));
            service('payroll')->calculate($id);
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/payroll/' . $id)->with('success', 'Payroll created and calculated. Review the warnings and lines before finalizing.');
    }

    public function show($id)
    {
        $this->authorize('hr.payroll.view');
        $run = $this->runOr404($this->idOr404($id));

        return $this->render('hr/payroll/show', [
            'pageTitle' => 'Payroll ' . date('F Y', strtotime($run['period'] . '-01')), 'breadcrumbs' => [['HR'], ['Payroll', 'hr/payroll'], [$run['period']]],
            'run' => $run, 'warnings' => service('payroll')->warnings((int) $run['id']), 'accounts' => $this->accounts(),
            'items' => $this->db->table('payroll_items i')->select('i.*, e.full_name, e.employee_code, (SELECT MAX(ps.stored_file_id) FROM payslips ps WHERE ps.payroll_item_id = i.id) AS payslip_file', false)
                ->join('employees e', 'e.id = i.employee_id')->where('i.payroll_run_id', $run['id'])->orderBy('e.full_name')->get()->getResultArray(),
            'adjustments' => $this->db->table('payroll_adjustments a')->select('a.*, e.full_name, p.full_name AS by_name')->join('payroll_items i', 'i.id = a.payroll_item_id')->join('employees e', 'e.id = i.employee_id')
                ->join('user_profiles p', 'p.user_id = a.created_by', 'left')->where('a.payroll_run_id', $run['id'])->orderBy('a.id')->get()->getResultArray(),
        ]);
    }

    public function item($id)
    {
        $this->authorize('hr.payroll.view');
        $item = $this->db->table('payroll_items i')->select('i.*, e.full_name, e.employee_code')->join('employees e', 'e.id = i.employee_id')->where('i.id', $this->idOr404($id))->get()->getRowArray() ?? $this->notFound();
        $run  = $this->runOr404((int) $item['payroll_run_id']);

        return $this->render('hr/payroll/item', [
            'pageTitle' => $item['full_name'] . ' — ' . $run['period'], 'breadcrumbs' => [['HR'], ['Payroll', 'hr/payroll'], [$run['period'], 'hr/payroll/' . $run['id']], [$item['employee_code']]],
            'item' => $item, 'run' => $run, 'accounts' => $this->accounts(),
            'components' => $this->db->table('payroll_item_components')->where('payroll_item_id', $item['id'])->orderBy('component_type')->orderBy('id')->get()->getResultArray(),
            'payments' => $this->db->table('payroll_payments p')->select('p.*, b.display_name')->join('bank_cash_accounts b', 'b.id = p.bank_cash_account_id')->where('p.payroll_item_id', $item['id'])->get()->getResultArray(),
            'payslips' => $this->db->table('payslips')->where('payroll_item_id', $item['id'])->orderBy('version', 'DESC')->get()->getResultArray(),
            'adjustments' => $this->db->table('payroll_adjustments')->where('payroll_item_id', $item['id'])->get()->getResultArray(),
        ]);
    }

    public function calculate($id)
    {
        $this->authorize('hr.payroll.calculate');
        $run = $this->runOr404($this->idOr404($id));
        try {
            $r = service('payroll')->calculate((int) $run['id']);
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/payroll/' . $run['id'])->with('success', 'Recalculated ' . $r['items'] . ' employee(s).' . ($r['skipped'] ? ' Skipped: ' . implode(', ', $r['skipped']) : ''));
    }

    public function finalize($id)
    {
        $this->authorize('hr.payroll.finalize');
        $run = $this->runOr404($this->idOr404($id));

        return $this->wrap(fn () => service('payroll')->finalize((int) $run['id']), 'Payroll finalized and locked. Accrual journal posted and payslips generated.', 'hr/payroll/' . $run['id']);
    }

    public function delete($id)
    {
        $this->authorize('hr.payroll.calculate');
        $run = $this->runOr404($this->idOr404($id));

        return $this->wrap(fn () => service('payroll')->deleteRun((int) $run['id']), 'Draft payroll deleted.', 'hr/payroll');
    }

    public function pay($id)
    {
        $this->authorize('hr.payroll.pay');
        $run = $this->runOr404($this->idOr404($id));
        $ids = array_map('intval', (array) $this->request->getPost('items'));

        return $this->wrap(fn () => service('payroll')->pay((int) $run['id'], $ids === [] ? null : $ids, $this->request->getPost()), 'Salary payment recorded.', 'hr/payroll/' . $run['id']);
    }

    public function payItem($id)
    {
        $this->authorize('hr.payroll.pay');
        $item = $this->db->table('payroll_items')->where('id', $this->idOr404($id))->get()->getRowArray() ?? $this->notFound();

        return $this->wrap(fn () => service('payroll')->pay((int) $item['payroll_run_id'], [(int) $item['id']], $this->request->getPost()), 'Payment recorded.', 'hr/payroll/items/' . $item['id']);
    }

    public function addLine($id)
    {
        $this->authorize('hr.payroll.calculate');
        $itemId = $this->idOr404($id);

        return $this->wrap(fn () => service('payroll')->addManualLine($itemId, (string) $this->request->getPost('component_type'), (string) $this->request->getPost('label'), (string) $this->request->getPost('amount')), 'Line added and payroll recalculated.', 'hr/payroll/items/' . $itemId);
    }

    public function removeLine($id)
    {
        $this->authorize('hr.payroll.calculate');

        return $this->wrap(fn () => service('payroll')->removeManualLine($this->idOr404($id)), 'Line removed and payroll recalculated.');
    }

    public function adjust($id)
    {
        $this->authorize('hr.payroll.adjust');
        $itemId = $this->idOr404($id);

        return $this->wrap(fn () => service('payroll')->adjust($itemId, (string) $this->request->getPost('adjustment_type'), (string) $this->request->getPost('label'), (string) $this->request->getPost('amount'), (string) $this->request->getPost('reason')),
            'Correction recorded with its own journal and a revised payslip.', 'hr/payroll/items/' . $itemId);
    }

    /** Payslip preview of an unlocked line (stored payslips are served through /files with ownership checks). */
    public function preview($id)
    {
        $this->authorize(['hr.payslip.view_all']);
        $item = $this->db->table('payroll_items')->where('id', $this->idOr404($id))->get()->getRowArray() ?? $this->notFound();

        return $this->pdfResponse(service('payslips')->render((int) $item['id']), 'payslip-preview.pdf');
    }

    public function register($id)
    {
        $this->authorize('hr.salary.export');
        $run  = $this->runOr404($this->idOr404($id));
        $rows = $this->db->table('payroll_items i')->select('e.employee_code, e.full_name, d.name AS department, i.days_in_period, i.payable_days, i.present_days, i.paid_leave_days, i.unpaid_leave_days, i.absent_days,
                i.base_monthly, i.fixed_earned, i.allowances, i.overtime_amount, i.incentive_amount, i.reimbursement_amount, i.gross_pay, i.statutory_deductions, i.recovery_deductions,
                i.other_deductions, i.total_deductions, i.net_pay, i.amount_paid, i.balance, i.payment_status, i.last_payment_date, b.account_last4')
            ->join('employees e', 'e.id = i.employee_id')->join('departments d', 'd.id = e.department_id', 'left')->join('employee_bank_details b', 'b.employee_id = e.id', 'left')
            ->where('i.payroll_run_id', $run['id'])->orderBy('e.employee_code')->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['account_last4'] = $r['account_last4'] ? '••••' . $r['account_last4'] : '';
        }
        unset($r);
        $col = static fn ($k, $l, $t = 'text', $w = 0) => array_filter(['key' => $k, 'label' => $l, 'type' => $t, 'width' => $w ?: null]);
        $m   = static fn ($k, $l) => $col($k, $l, 'money', 13);

        return service('exports')->download('salary_register', 'xlsx', 'salary-register-' . $run['period'], [[
            'title' => 'Salary register ' . $run['period'], 'rows' => $rows,
            'columns' => [$col('employee_code', 'Emp ID'), $col('full_name', 'Employee', 'text', 24), $col('department', 'Department', 'text', 18), $col('days_in_period', 'Days', 'number'),
                $col('payable_days', 'Payable days', 'number'), $col('present_days', 'Present', 'number'), $col('paid_leave_days', 'Paid leave', 'number'), $col('unpaid_leave_days', 'Unpaid leave', 'number'),
                $col('absent_days', 'Absent', 'number'), $m('base_monthly', 'Monthly gross'), $m('fixed_earned', 'Fixed earned'), $m('allowances', 'Allowances'), $m('overtime_amount', 'Overtime'),
                $m('incentive_amount', 'Incentives'), $m('reimbursement_amount', 'Reimbursements'), $m('gross_pay', 'Gross pay'), $m('statutory_deductions', 'Statutory'),
                $m('recovery_deductions', 'Recoveries'), $m('other_deductions', 'Other deductions'), $m('total_deductions', 'Total deductions'), $m('net_pay', 'Net pay'),
                $m('amount_paid', 'Paid'), $m('balance', 'Balance'), $col('payment_status', 'Status'), $col('last_payment_date', 'Paid on', 'date'), $col('account_last4', 'Bank a/c')],
        ]], ['period' => $run['period']], ['Report' => 'Salary register', 'Payroll status' => $run['status'] . ((int) $run['is_locked'] ? ' (locked)' : ' (DRAFT — not final)')]);
    }

    public function statutory()
    {
        $this->authorize('hr.payroll.statutory');

        return $this->render('hr/payroll/statutory', ['pageTitle' => 'Statutory deductions', 'breadcrumbs' => [['HR'], ['Payroll', 'hr/payroll'], ['Statutory']], 'items' => service('salaries')->statutory()]);
    }

    public function saveStatutory($code)
    {
        $this->authorize('hr.payroll.statutory');

        return $this->wrap(fn () => service('salaries')->saveStatutory((string) $code, $this->request->getPost()), $code . ' settings saved.', 'hr/payroll/statutory');
    }

    public function advance()
    {
        $this->authorize('hr.payroll.pay');

        return $this->wrap(fn () => service('payroll')->giveAdvance((int) $this->request->getPost('employee_id'), $this->request->getPost()), 'Advance recorded; it will be recovered through payroll.', 'hr/payroll');
    }
}
