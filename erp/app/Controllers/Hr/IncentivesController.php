<?php

namespace App\Controllers\Hr;

use App\Exceptions\BusinessRuleException;
use App\Services\Hr\IncentiveService;

/** Incentive ledger (proposals, approvals, splits, clawbacks) and policies. */
class IncentivesController extends HrBase
{
    public function index()
    {
        $this->authorize(['hr.incentive.view_all', 'hr.incentive.policy_manage']);
        $f = [
            'period' => preg_match('/^\d{4}-\d{2}$/', (string) $this->request->getGet('period')) ? (string) $this->request->getGet('period') : '',
            'employee' => (int) $this->request->getGet('employee'), 'status' => (string) $this->request->getGet('status'),
        ];
        $b = $this->db->table('incentive_entries ie')->select('ie.*, e.full_name, e.employee_code, p.name AS policy, l.company_name, d.document_number, r.receipt_number, pr.period AS paid_period')
            ->join('employees e', 'e.id = ie.employee_id')->join('incentive_policies p', 'p.id = ie.policy_id')->join('leads l', 'l.id = ie.lead_id', 'left')
            ->join('billing_documents d', 'd.id = ie.document_id', 'left')->join('payment_receipts r', 'r.id = ie.receipt_id', 'left')
            ->join('payroll_items pi', 'pi.id = ie.payroll_item_id', 'left')->join('payroll_runs pr', 'pr.id = pi.payroll_run_id', 'left');
        if ($f['period']) {
            $b->where('ie.period', $f['period']);
        }
        if ($f['employee']) {
            $b->where('ie.employee_id', $f['employee']);
        }
        if (in_array($f['status'], ['proposed', 'approved', 'rejected', 'included', 'paid', 'clawed_back'], true)) {
            $b->where('ie.status', $f['status']);
        }
        $rows = $b->orderBy("ie.status = 'proposed'", 'DESC', false)->orderBy('ie.id', 'DESC')->limit(300)->get()->getResultArray();
        $sum  = [];
        foreach ($rows as $r) {
            $sum[$r['status']] = \App\Libraries\Dec::add($sum[$r['status']] ?? '0', $r['amount']);
        }

        return $this->render('hr/incentives/index', [
            'pageTitle' => 'Incentive ledger', 'breadcrumbs' => [['HR'], ['Incentives']], 'rows' => $rows, 'f' => $f, 'sum' => $sum,
            'employees' => service('employees')->picker(true), 'policies' => $this->db->table('incentive_policies')->orderBy('is_active', 'DESC')->orderBy('name')->get()->getResultArray(),
            'bases' => IncentiveService::BASES,
        ]);
    }

    public function generate()
    {
        $this->authorize(['hr.incentive.approve', 'hr.incentive.policy_manage']);
        $period = (string) $this->request->getPost('period');
        try {
            $r = service('incentives')->generate($period);
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/incentives?period=' . $period)->with('success', sprintf('%d new proposal(s); %d already existed (no duplicates); %d below threshold; %d reversed/clawed back.', $r['created'], $r['skipped'], $r['below_threshold'], $r['reversed']));
    }

    public function decide($id)
    {
        $this->authorize('hr.incentive.approve');
        try {
            service('incentives')->decide($this->idOr404($id), $this->request->getPost('decision') === 'approved' ? 'approved' : 'rejected', trim((string) $this->request->getPost('reason')));
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->back()->with('success', 'Incentive entry updated.');
    }

    public function bulkApprove()
    {
        $this->authorize('hr.incentive.approve');
        $n = 0;
        $errors = [];
        foreach (array_map('intval', (array) $this->request->getPost('ids')) as $id) {
            try {
                service('incentives')->decide($id, 'approved');
                $n++;
            } catch (BusinessRuleException $e) {
                $errors[] = '#' . $id . ': ' . $e->getMessage();
            }
        }

        return redirect()->back()->with($errors ? 'error' : 'success', $n . ' approved.' . ($errors ? ' ' . implode(' ', array_slice($errors, 0, 3)) : ''));
    }

    public function split($id)
    {
        $this->authorize('hr.incentive.approve');
        try {
            service('incentives')->split($this->idOr404($id), (int) $this->request->getPost('employee_id'), (float) $this->request->getPost('percent'), trim((string) $this->request->getPost('reason')));
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->back()->with('success', 'Credit split.');
    }

    public function manual()
    {
        $this->authorize('hr.incentive.approve');
        try {
            service('incentives')->addManual($this->request->getPost());
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/incentives?status=proposed')->with('success', 'Manual entry added for approval.');
    }

    public function policies()
    {
        $this->authorize('hr.incentive.policy_manage');
        $edit = (int) $this->request->getGet('edit');

        return $this->render('hr/incentives/policies', [
            'pageTitle' => 'Incentive policies', 'breadcrumbs' => [['HR'], ['Incentives', 'hr/incentives'], ['Policies']],
            'policies' => $this->db->table('incentive_policies')->orderBy('is_active', 'DESC')->orderBy('name')->get()->getResultArray(),
            'edit' => $edit ? $this->db->table('incentive_policies')->where('id', $edit)->get()->getRowArray() : null,
            'bases' => IncentiveService::BASES, 'roles' => array_column($this->db->table('roles')->select('code, name')->where('is_active', 1)->get()->getResultArray(), 'name', 'code'),
            'users' => $this->users(), 'services' => array_column($this->db->table('services')->select('id, name')->where('is_active', 1)->orderBy('name')->get()->getResultArray(), 'name', 'id'),
        ]);
    }

    public function savePolicy($id = null)
    {
        $this->authorize('hr.incentive.policy_manage');
        try {
            service('incentives')->savePolicy($this->request->getPost(), $id !== null ? $this->idOr404($id) : null);
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/incentives/policies')->with('success', 'Policy saved.');
    }
}
