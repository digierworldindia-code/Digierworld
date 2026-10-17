<?php

namespace App\Controllers\Finance;

use App\Controllers\BaseController;
use App\Exceptions\BusinessRuleException;
use App\Services\Finance\ReceiptService;

/** Expenses / purchases: record with GST & ITC, approve (SoD), pay, attach bills, recurring reminders. */
class ExpensesController extends BaseController
{
    private function viewAll(): bool
    {
        return $this->can('finance.expense.view_all');
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        $g = $this->request->getGet();

        return [
            'q'        => trim((string) ($g['q'] ?? '')),
            'category' => (int) ($g['category'] ?? 0),
            'approval' => in_array($g['approval'] ?? '', ['pending', 'approved', 'rejected'], true) ? $g['approval'] : '',
            'payment'  => in_array($g['payment'] ?? '', ['unpaid', 'partial', 'paid'], true) ? $g['payment'] : '',
            'through'  => in_array($g['through'] ?? '', ['company', 'employee_reimbursable', 'unpaid'], true) ? $g['through'] : '',
            'vendor'   => (int) ($g['vendor'] ?? 0),
            'from'     => ymd((string) ($g['from'] ?? '')),
            'to'       => ymd((string) ($g['to'] ?? '')),
        ];
    }

    private function query(array $f, ?string $select = null)
    {
        $b = $this->db->table('expenses e')
            ->select($select ?? 'e.*, c.name AS category_name, v.name AS vendor_name, p.full_name AS creator', $select === null)
            ->join('expense_categories c', 'c.id = e.category_id')->join('contacts v', 'v.id = e.vendor_contact_id', 'left')
            ->join('user_profiles p', 'p.user_id = e.created_by', 'left')->where('e.deleted_at', null);
        if (! $this->viewAll()) {
            $b->where('e.created_by', (int) $this->userId);
        }
        if ($f['q'] !== '') {
            $b->groupStart()->like('e.expense_number', $f['q'])->orLike('e.description', $f['q'])->orLike('e.vendor_invoice_no', $f['q'])->orLike('v.name', $f['q'])->groupEnd();
        }
        foreach (['category' => 'e.category_id', 'vendor' => 'e.vendor_contact_id'] as $k => $col) {
            if ($f[$k] > 0) {
                $b->where($col, $f[$k]);
            }
        }
        foreach (['approval' => 'e.approval_status', 'payment' => 'e.payment_status', 'through' => 'e.paid_through'] as $k => $col) {
            if ($f[$k] !== '') {
                $b->where($col, $f[$k]);
            }
        }
        if ($f['from']) {
            $b->where('e.expense_date >=', $f['from']);
        }
        if ($f['to']) {
            $b->where('e.expense_date <=', $f['to']);
        }

        return $b;
    }

    public function index()
    {
        $f = $this->filters();
        [$page, $perPage, $offset] = $this->paging(25);

        return $this->render('finance/expenses/index', [
            'pageTitle' => 'Expenses', 'breadcrumbs' => [['Finance'], ['Expenses']],
            'rows' => $this->query($f)->orderBy('e.expense_date', 'DESC')->orderBy('e.id', 'DESC')->limit($perPage, $offset)->get()->getResultArray(),
            'total' => $this->query($f)->countAllResults(), 'page' => $page, 'perPage' => $perPage, 'f' => $f,
            'sums' => $this->query($f, "COALESCE(SUM(CASE WHEN e.approval_status = 'approved' THEN e.total_amount END),0) approved, COALESCE(SUM(CASE WHEN e.approval_status = 'approved' THEN e.itc_eligible_amount END),0) itc, COALESCE(SUM(CASE WHEN e.approval_status = 'approved' THEN e.total_amount - e.paid_amount END),0) unpaid, COALESCE(SUM(CASE WHEN e.approval_status = 'pending' THEN 1 ELSE 0 END),0) pending")->get()->getRowArray(),
            'categories' => $this->categories(),
            'dueRecurring' => $this->db->table('recurring_expenses')->where('is_active', 1)->where('next_due_date <=', date('Y-m-d', strtotime('+7 days')))->countAllResults(),
        ]);
    }

    public function export()
    {
        $this->authorize('finance.expense.export');
        $f    = $this->filters();
        $rows = $this->query($f)->orderBy('e.expense_date')->orderBy('e.id')->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['gst_label'] = gst_type_label($r['gst_type']);
            $r['tax_total'] = \App\Libraries\Dec::add($r['cgst_amount'], $r['sgst_amount'], $r['utgst_amount'], $r['igst_amount']);
        }
        unset($r);
        $cols = [
            ['key' => 'expense_number', 'label' => 'Expense no.'], ['key' => 'expense_date', 'label' => 'Date', 'type' => 'date'], ['key' => 'category_name', 'label' => 'Category', 'width' => 24],
            ['key' => 'vendor_name', 'label' => 'Vendor', 'width' => 26], ['key' => 'vendor_gstin', 'label' => 'Vendor GSTIN'], ['key' => 'vendor_invoice_no', 'label' => 'Vendor invoice'],
            ['key' => 'vendor_invoice_date', 'label' => 'Invoice date', 'type' => 'date'], ['key' => 'description', 'label' => 'Description', 'width' => 36], ['key' => 'sac_hsn', 'label' => 'SAC/HSN'],
            ['key' => 'taxable_amount', 'label' => 'Taxable', 'type' => 'money'], ['key' => 'gst_label', 'label' => 'GST type'], ['key' => 'gst_rate', 'label' => 'Rate %'],
            ['key' => 'cgst_amount', 'label' => 'CGST', 'type' => 'money'], ['key' => 'sgst_amount', 'label' => 'SGST', 'type' => 'money'], ['key' => 'utgst_amount', 'label' => 'UTGST', 'type' => 'money'],
            ['key' => 'igst_amount', 'label' => 'IGST', 'type' => 'money'], ['key' => 'total_amount', 'label' => 'Total', 'type' => 'money'],
            ['key' => 'itc_eligible_amount', 'label' => 'ITC eligible', 'type' => 'money'], ['key' => 'itc_ineligible_amount', 'label' => 'ITC not eligible', 'type' => 'money'],
            ['key' => 'approval_status', 'label' => 'Approval'], ['key' => 'payment_status', 'label' => 'Payment'], ['key' => 'paid_amount', 'label' => 'Paid', 'type' => 'money'],
        ];

        return service('exports')->download('expense_register', (string) $this->request->getGet('format'), 'Expense_register', [['title' => 'Expenses', 'columns' => $cols, 'rows' => $rows]], $f, ['Report' => 'Expense register']);
    }

    /** @return list<array<string, mixed>> */
    private function categories(): array
    {
        return $this->db->table('expense_categories c')->select('c.id, c.name, c.default_itc_rule_id')->where('c.is_active', 1)->orderBy('c.name')->get()->getResultArray();
    }

    private function canSetItc(): bool
    {
        return $this->can(['finance.expense.approve', 'finance.expense.manage_categories']);
    }

    private function form(?array $e, array $prefill = [])
    {
        return $this->render('finance/expenses/form', [
            'pageTitle' => $e ? 'Edit ' . $e['expense_number'] : 'Record expense',
            'breadcrumbs' => [['Finance'], ['Expenses', 'finance/expenses'], [$e ? $e['expense_number'] : 'Record']],
            'e' => $e, 'prefill' => $prefill, 'categories' => $this->categories(),
            'itcRules' => $this->db->table('itc_rules')->where('is_active', 1)->orderBy('id')->get()->getResultArray(),
            'canSetItc' => $this->canSetItc(),
            'vendor' => ($vid = (int) ($e['vendor_contact_id'] ?? $prefill['vendor_contact_id'] ?? 0)) ? $this->db->table('contacts')->select('id, name, gstin, state_code')->where('id', $vid)->get()->getRowArray() : null,
            'employees' => $this->db->table('employees')->select('id, full_name, employee_code')->where('employment_status', 'active')->orderBy('full_name')->get()->getResultArray(),
            'companyState' => (string) ($this->db->table('company_settings')->select('state_code')->where('id', 1)->get()->getRow()->state_code ?? ''),
            'rates' => array_column($this->db->table('tax_rates')->select('rate')->where('is_active', 1)->orderBy('rate')->get()->getResultArray(), 'rate'),
        ]);
    }

    public function new()
    {
        $prefill = [];
        if ($rid = (int) $this->request->getGet('recurring')) {
            $r = $this->db->table('recurring_expenses')->where('id', $rid)->get()->getRowArray();
            if ($r) {
                $prefill = ['recurring_expense_id' => $r['id'], 'category_id' => $r['category_id'], 'vendor_contact_id' => $r['vendor_contact_id'], 'description' => $r['name'], 'taxable_amount' => $r['amount']];
            }
        }

        return $this->form(null, $prefill);
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        $in = $this->request->getPost();
        if (! $this->canSetItc()) {
            unset($in['itc_rule_id']); // category default applies; accountant can change it on review
        }

        return $in;
    }

    public function create()
    {
        try {
            $id = service('expenses')->create($this->input());
            $this->attachUploads($id);
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }
        if ($rid = (int) $this->request->getPost('recurring_expense_id')) {
            $this->advanceRecurring($rid);
        }

        return redirect()->to(site_url('finance/expenses/' . $id))->with('success', 'Expense recorded and sent for approval.');
    }

    private function find(int $id): array
    {
        $b = $this->db->table('expenses e')
            ->select('e.*, c.name AS category_name, v.name AS vendor_name, v.state_code AS vendor_state, p.full_name AS creator, ap.full_name AS approver, r.name AS itc_rule_name, r.review_status AS itc_review, em.full_name AS employee_name')
            ->join('expense_categories c', 'c.id = e.category_id')->join('contacts v', 'v.id = e.vendor_contact_id', 'left')
            ->join('user_profiles p', 'p.user_id = e.created_by', 'left')->join('user_profiles ap', 'ap.user_id = e.approved_by', 'left')
            ->join('itc_rules r', 'r.id = e.itc_rule_id', 'left')->join('employees em', 'em.id = e.paid_by_employee_id', 'left')
            ->where('e.id', $id)->where('e.deleted_at', null);
        if (! $this->viewAll()) {
            $b->where('e.created_by', (int) $this->userId);
        }

        return $b->get()->getRowArray() ?? $this->notFound();
    }

    public function show($id)
    {
        $e = $this->find($this->idOr404($id));

        return $this->render('finance/expenses/show', [
            'pageTitle' => 'Expense ' . $e['expense_number'], 'breadcrumbs' => [['Finance'], ['Expenses', 'finance/expenses'], [$e['expense_number']]],
            'e' => $e,
            'files' => $this->db->table('expense_files x')->select('f.id, f.original_name, f.size_bytes, x.created_at')->join('stored_files f', 'f.id = x.stored_file_id')
                ->where('x.expense_id', $e['id'])->where('f.deleted_at', null)->get()->getResultArray(),
            'payments' => $this->db->table('expense_payments x')->select('x.*, a.display_name AS account_name')->join('bank_cash_accounts a', 'a.id = x.bank_cash_account_id')
                ->where('x.expense_id', $e['id'])->orderBy('x.id')->get()->getResultArray(),
            'history' => $this->db->table('audit_logs a')->select('a.occurred_at, a.action, a.summary, p.full_name')->join('user_profiles p', 'p.user_id = a.user_id', 'left')
                ->where(['a.entity_type' => 'expense', 'a.entity_id' => $e['id']])->orderBy('a.id')->get()->getResultArray(),
            'accounts' => $this->can('finance.expense.pay') ? $this->db->table('bank_cash_accounts')->select('id, display_name, account_kind')->where('is_active', 1)->orderBy('display_priority')->get()->getResultArray() : [],
            'modes' => ReceiptService::MODES,
            'itcRules' => $this->db->table('itc_rules')->where('is_active', 1)->orderBy('id')->get()->getResultArray(),
            'canEdit' => $e['approval_status'] === 'pending' && ($this->can('finance.expense.edit') || (int) $e['created_by'] === (int) $this->userId),
            'canApprove' => $e['approval_status'] === 'pending' && $this->can('finance.expense.approve') && ((int) $e['created_by'] !== (int) $this->userId || $this->authz->isSuper()),
            'sodBlocked' => $e['approval_status'] === 'pending' && $this->can('finance.expense.approve') && (int) $e['created_by'] === (int) $this->userId && ! $this->authz->isSuper(),
        ]);
    }

    public function edit($id)
    {
        $e = $this->find($this->idOr404($id));
        if ($e['approval_status'] !== 'pending') {
            return redirect()->to(site_url('finance/expenses/' . $e['id']))->with('error', 'Approved or rejected expenses cannot be edited.');
        }
        if (! $this->can('finance.expense.edit') && (int) $e['created_by'] !== (int) $this->userId) {
            $this->deny();
        }

        return $this->form($e);
    }

    public function update($id)
    {
        $e = $this->find($this->idOr404($id));
        if (! $this->can('finance.expense.edit') && (int) $e['created_by'] !== (int) $this->userId) {
            $this->deny();
        }
        $in = $this->input();
        if (! $this->canSetItc()) {
            $in['itc_rule_id'] = $e['itc_rule_id'];
        }

        try {
            service('expenses')->update((int) $e['id'], $in);
            $this->attachUploads((int) $e['id']);
        } catch (BusinessRuleException $ex) {
            return $this->backWithRuleError($ex);
        }

        return redirect()->to(site_url('finance/expenses/' . $e['id']))->with('success', 'Expense updated.');
    }

    private function attachUploads(int $expenseId): void
    {
        foreach ($this->request->getFileMultiple('bills') ?? [] as $file) {
            if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $fid = service('files')->storeUpload($file, 'doc', 'expense', 'expense', $expenseId);
            $this->db->table('expense_files')->insert(['expense_id' => $expenseId, 'stored_file_id' => $fid]);
            service('audit')->log('expense.file_attached', 'expense', $expenseId, null, ['file_id' => $fid], 'Bill attached');
        }
    }

    public function attach($id)
    {
        $e = $this->find($this->idOr404($id));

        try {
            $this->attachUploads((int) $e['id']);
        } catch (BusinessRuleException $ex) {
            return redirect()->back()->with('error', $ex->getMessage());
        }

        return redirect()->back()->with('success', 'Bill attached.');
    }

    public function approve($id)
    {
        $e = $this->find($this->idOr404($id));
        if ($this->canSetItc() && ($rule = (int) $this->request->getPost('itc_rule_id')) > 0 && $rule !== (int) $e['itc_rule_id'] && $e['approval_status'] === 'pending') {
            // Accountant's ITC decision at approval time recomputes eligible/ineligible tax
            $current = $this->db->table('expenses')->where('id', $e['id'])->get()->getRowArray();
            try {
                service('expenses')->update((int) $e['id'], ['itc_rule_id' => $rule, 'allow_duplicate' => true] + $current);
            } catch (BusinessRuleException $ex) {
                return redirect()->back()->with('error', $ex->getMessage());
            }
        }

        try {
            service('expenses')->approve((int) $e['id']);
        } catch (BusinessRuleException $ex) {
            return redirect()->back()->with('error', $ex->getMessage());
        }

        return redirect()->to(site_url('finance/expenses/' . $e['id']))->with('success', 'Expense approved and posted to the books.');
    }

    public function reject($id)
    {
        $e      = $this->find($this->idOr404($id));
        $reason = trim((string) $this->request->getPost('reason'));
        if (mb_strlen($reason) < 5) {
            return redirect()->back()->with('error', 'Give a reason for rejecting.');
        }

        try {
            service('expenses')->reject((int) $e['id'], $reason);
        } catch (BusinessRuleException $ex) {
            return redirect()->back()->with('error', $ex->getMessage());
        }

        return redirect()->to(site_url('finance/expenses/' . $e['id']))->with('success', 'Expense rejected.');
    }

    public function pay($id)
    {
        $e = $this->find($this->idOr404($id));

        try {
            service('expenses')->pay((int) $e['id'], $this->request->getPost());
        } catch (BusinessRuleException $ex) {
            return redirect()->back()->with('error', $ex->getMessage());
        }

        return redirect()->to(site_url('finance/expenses/' . $e['id']))->with('success', 'Payment recorded.');
    }

    // ---- recurring reminders -------------------------------------------

    public function recurring()
    {
        return $this->render('finance/expenses/recurring', [
            'pageTitle' => 'Recurring expenses', 'breadcrumbs' => [['Finance'], ['Expenses', 'finance/expenses'], ['Recurring']],
            'rows' => $this->db->table('recurring_expenses r')->select('r.*, c.name AS category_name, v.name AS vendor_name')
                ->join('expense_categories c', 'c.id = r.category_id')->join('contacts v', 'v.id = r.vendor_contact_id', 'left')
                ->orderBy('r.is_active', 'DESC')->orderBy('r.next_due_date')->get()->getResultArray(),
            'categories' => $this->categories(),
            'vendors' => $this->db->table('contacts')->select('id, name')->where(['is_vendor' => 1, 'is_active' => 1, 'deleted_at' => null])->orderBy('name')->get()->getResultArray(),
            'edit' => ($rid = (int) $this->request->getGet('edit')) ? $this->db->table('recurring_expenses')->where('id', $rid)->get()->getRowArray() : null,
        ]);
    }

    public function saveRecurring($id = null)
    {
        $in   = $this->request->getPost();
        $data = [
            'name' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 150), 'category_id' => (int) ($in['category_id'] ?? 0),
            'vendor_contact_id' => ((int) ($in['vendor_contact_id'] ?? 0)) ?: null, 'amount' => number_format((float) str_replace(',', '', (string) ($in['amount'] ?? '0')), 2, '.', ''),
            'frequency' => in_array($in['frequency'] ?? '', ['monthly', 'quarterly', 'half_yearly', 'yearly'], true) ? $in['frequency'] : 'monthly',
            'next_due_date' => ymd((string) ($in['next_due_date'] ?? '')), 'reminder_days_before' => max(0, min(60, (int) ($in['reminder_days_before'] ?? 5))),
            'notes' => trim((string) ($in['notes'] ?? '')) ?: null, 'is_active' => empty($in['is_active']) && $id !== null ? 0 : 1, 'updated_by' => $this->userId,
        ];
        if ($data['name'] === '' || $data['category_id'] <= 0 || $data['next_due_date'] === null || (float) $data['amount'] <= 0) {
            return redirect()->back()->withInput()->with('error', 'Name, category, amount and next due date are required.');
        }
        if ($id === null) {
            $this->db->table('recurring_expenses')->insert($data + ['created_by' => $this->userId]);
            $id = (int) $this->db->insertID();
        } else {
            $this->db->table('recurring_expenses')->where('id', (int) $id)->update($data);
        }
        service('audit')->log('recurring_expense.saved', 'recurring_expense', (int) $id, null, $data, 'Recurring expense saved: ' . $data['name']);

        return redirect()->to(site_url('finance/expenses/recurring'))->with('success', 'Saved. You will be reminded ' . $data['reminder_days_before'] . ' days before it is due.');
    }

    private function advanceRecurring(int $rid): void
    {
        $r = $this->db->table('recurring_expenses')->where('id', $rid)->get()->getRowArray();
        if ($r === null) {
            return;
        }
        $months = ['monthly' => 1, 'quarterly' => 3, 'half_yearly' => 6, 'yearly' => 12][$r['frequency']];
        $this->db->table('recurring_expenses')->where('id', $rid)->update(['next_due_date' => date('Y-m-d', strtotime($r['next_due_date'] . " +{$months} months"))]);
    }
}
