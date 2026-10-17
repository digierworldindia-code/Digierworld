<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRuleException;
use App\Libraries\Dec;
use App\Services\Billing\DocumentNumberAllocator;
use CodeIgniter\Database\BaseConnection;

/**
 * Expenses / purchases with GST breakup and rule-based ITC.
 *
 * ITC is never assumed: eligibility comes from the accountant-configurable
 * ITC rule on the expense, and is forced to zero when the vendor GSTIN is
 * missing. Approval posts the accrual (Dr expense + eligible input tax /
 * Cr payable); payment later moves Dr payable / Cr bank — no double posting.
 */
class ExpenseService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly LedgerService $ledger,
        private readonly DocumentNumberAllocator $numbers,
    ) {
    }

    /**
     * @param array<string, mixed> $in
     *
     * @return array<string, mixed> normalised columns
     */
    public function normalize(array $in, bool $allowDuplicate = false, ?int $excludeId = null): array
    {
        $errors   = [];
        $date     = ymd((string) ($in['expense_date'] ?? '')) ?? null;
        $category = $this->db->table('expense_categories')->where(['id' => (int) ($in['category_id'] ?? 0), 'is_active' => 1])->get()->getRowArray();
        $taxable  = Dec::round(str_replace(',', '', (string) ($in['taxable_amount'] ?? '0')));
        $gstType  = in_array($in['gst_type'] ?? 'none', ['cgst_sgst', 'cgst_utgst', 'igst', 'none'], true) ? ($in['gst_type'] ?? 'none') : 'none';
        $rate     = Dec::round((string) ($in['gst_rate'] ?? '0'), 3);
        $vendorId = ! empty($in['vendor_contact_id']) ? (int) $in['vendor_contact_id'] : null;
        $vendor   = $vendorId ? $this->db->table('contacts')->where('id', $vendorId)->where('deleted_at', null)->get()->getRowArray() : null;
        $gstin    = \App\Libraries\Gstin::normalize($in['vendor_gstin'] ?? ($vendor['gstin'] ?? null));

        if ($date === null) {
            $errors['expense_date'] = 'Expense date is required.';
        } elseif ($date > date('Y-m-d')) {
            $errors['expense_date'] = 'Expense date cannot be in the future.';
        }
        if ($category === null) {
            $errors['category_id'] = 'Choose a category.';
        }
        if (Dec::isNegative($taxable) || Dec::isZero($taxable)) {
            $errors['taxable_amount'] = 'Enter the amount before tax.';
        }
        if (trim((string) ($in['description'] ?? '')) === '') {
            $errors['description'] = 'Describe the expense.';
        }
        if ($vendorId && $vendor === null) {
            $errors['vendor_contact_id'] = 'Unknown vendor.';
        }
        if ($gstin !== null && ! \App\Libraries\Gstin::validate($gstin)['valid']) {
            $errors['vendor_gstin'] = 'Vendor GSTIN is invalid.';
        }
        if ($gstType !== 'none' && ! Dec::isPositive($rate)) {
            $errors['gst_rate'] = 'Enter the GST rate charged by the vendor.';
        }
        if ($errors !== []) {
            throw new BusinessRuleException('Please correct the highlighted fields.', $errors);
        }

        $half = Dec::div($rate, '2', 3);
        $tax  = ['cgst_amount' => '0.00', 'sgst_amount' => '0.00', 'utgst_amount' => '0.00', 'igst_amount' => '0.00'];
        match ($gstType) {
            'cgst_sgst'  => $tax = ['cgst_amount' => Dec::pct($taxable, $half), 'sgst_amount' => Dec::pct($taxable, $half)] + $tax,
            'cgst_utgst' => $tax = ['cgst_amount' => Dec::pct($taxable, $half), 'utgst_amount' => Dec::pct($taxable, $half)] + $tax,
            'igst'       => $tax = ['igst_amount' => Dec::pct($taxable, $rate)] + $tax,
            default      => null,
        };
        $taxTotal = Dec::add(...array_values($tax));
        $ruleId   = ! empty($in['itc_rule_id']) ? (int) $in['itc_rule_id'] : ($category['default_itc_rule_id'] ?? null);
        $rule     = $ruleId ? $this->db->table('itc_rules')->where('id', $ruleId)->get()->getRowArray() : null;
        $pct      = $rule !== null && (int) $rule['eligible'] === 1 ? $rule['eligible_percent'] : '0';
        if ($gstin === null) {
            $pct = '0'; // no supplier GSTIN → no ITC
        }
        $eligible   = Dec::pct($taxTotal, $pct);
        $ineligible = Dec::sub($taxTotal, $eligible);

        $vendorInv = trim((string) ($in['vendor_invoice_no'] ?? '')) ?: null;
        $hash      = hash('sha256', implode('|', [$vendorId ?? 0, $date, Dec::add($taxable, $taxTotal), strtolower((string) $vendorInv)]));
        if (! $allowDuplicate) {
            if ($vendorId && $vendorInv !== null) {
                $d = $this->db->table('expenses')->where(['vendor_contact_id' => $vendorId, 'vendor_invoice_no' => $vendorInv, 'deleted_at' => null])->where('id !=', $excludeId ?? 0)->get()->getRowArray();
                if ($d !== null) {
                    throw new BusinessRuleException('Vendor invoice ' . $vendorInv . ' from this vendor is already recorded as ' . $d['expense_number'] . '.', ['duplicate' => $d['expense_number']]);
                }
            }
            $d = $this->db->table('expenses')->where(['dedupe_hash' => $hash, 'deleted_at' => null])->where('id !=', $excludeId ?? 0)->get()->getRowArray();
            if ($d !== null) {
                throw new BusinessRuleException('An expense with the same vendor, date and amount exists (' . $d['expense_number'] . '). Tick "Not a duplicate" to save anyway.', ['duplicate' => $d['expense_number']]);
            }
        }
        $paidThrough = in_array($in['paid_through'] ?? 'unpaid', ['company', 'employee_reimbursable', 'unpaid'], true) ? ($in['paid_through'] ?? 'unpaid') : 'unpaid';

        return [
            'expense_date' => $date, 'category_id' => (int) $category['id'], 'vendor_contact_id' => $vendorId, 'vendor_invoice_no' => $vendorInv,
            'vendor_invoice_date' => ymd((string) ($in['vendor_invoice_date'] ?? '')), 'vendor_gstin' => $gstin,
            'description' => mb_substr(trim((string) $in['description']), 0, 500), 'sac_hsn' => preg_replace('/\D/', '', (string) ($in['sac_hsn'] ?? '')) ?: null,
            'project_contact_id' => ! empty($in['project_contact_id']) ? (int) $in['project_contact_id'] : null,
            'taxable_amount' => $taxable, 'gst_type' => $gstType, 'gst_rate' => $gstType === 'none' ? '0.000' : $rate,
            'total_amount' => Dec::add($taxable, $taxTotal), 'itc_rule_id' => $ruleId, 'itc_eligible_amount' => $eligible, 'itc_ineligible_amount' => $ineligible,
            'paid_through' => $paidThrough, 'paid_by_employee_id' => $paidThrough === 'employee_reimbursable' && ! empty($in['paid_by_employee_id']) ? (int) $in['paid_by_employee_id'] : null,
            'reimbursement_status' => $paidThrough === 'employee_reimbursable' ? 'pending' : 'na',
            'recurring_expense_id' => ! empty($in['recurring_expense_id']) ? (int) $in['recurring_expense_id'] : null,
            'dedupe_hash' => $hash, 'notes' => trim((string) ($in['notes'] ?? '')) ?: null,
        ] + $tax;
    }

    /** @param array<string, mixed> $in */
    public function create(array $in): int
    {
        $data = $this->normalize($in, ! empty($in['allow_duplicate']));
        $this->db->transBegin();
        try {
            $no = $this->numbers->allocate('expense', $data['expense_date'])['formatted'];
            $this->db->table('expenses')->insert($data + ['expense_number' => $no, 'approval_status' => 'pending', 'created_by' => service('authz')->currentUserId()]);
            $id = (int) $this->db->insertID();
            service('audit')->log('expense.created', 'expense', $id, null, $data, 'Expense ' . $no . ' ' . inr($data['total_amount']));
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        return $id;
    }

    /** @param array<string, mixed> $in */
    public function update(int $id, array $in): void
    {
        $before = $this->find($id);
        if ($before['approval_status'] !== 'pending') {
            throw new BusinessRuleException('Approved or rejected expenses cannot be edited. Record a correcting entry instead.');
        }
        $data = $this->normalize($in, ! empty($in['allow_duplicate']), $id);
        $this->db->table('expenses')->where('id', $id)->update($data + ['updated_by' => service('authz')->currentUserId()]);
        service('audit')->log('expense.updated', 'expense', $id, $before, $data, 'Expense ' . $before['expense_number'] . ' updated');
    }

    public function approve(int $id): void
    {
        $this->db->transBegin();
        try {
            $e = $this->db->query('SELECT * FROM expenses WHERE id = ? FOR UPDATE', [$id])->getRowArray() ?? throw new BusinessRuleException('Expense not found.');
            if ($e['approval_status'] !== 'pending') {
                throw new BusinessRuleException('Expense is already ' . $e['approval_status'] . '.');
            }
            $uid = service('authz')->currentUserId();
            if ((int) $e['created_by'] === (int) $uid && ! service('authz')->isSuper()) {
                throw new BusinessRuleException('Separation of duties: you cannot approve an expense you recorded. Ask another approver.');
            }
            $cat = $this->db->table('expense_categories')->where('id', $e['category_id'])->get()->getRowArray();
            $taxTotal = Dec::add($e['cgst_amount'], $e['sgst_amount'], $e['utgst_amount'], $e['igst_amount']);
            $lines = [['account' => (int) $cat['ledger_account_id'], 'debit' => Dec::add($e['taxable_amount'], $e['itc_ineligible_amount']), 'contact_id' => $e['vendor_contact_id'] ? (int) $e['vendor_contact_id'] : null, 'memo' => $e['description']]];
            // eligible ITC split across components in proportion, last component absorbs paise
            $remaining = $e['itc_eligible_amount'];
            $components = array_values(array_filter(['cgst' => $e['cgst_amount'], 'sgst' => $e['sgst_amount'], 'utgst' => $e['utgst_amount'], 'igst' => $e['igst_amount']], static fn ($v) => Dec::isPositive($v)));
            $keys = array_keys(array_filter(['cgst' => $e['cgst_amount'], 'sgst' => $e['sgst_amount'], 'utgst' => $e['utgst_amount'], 'igst' => $e['igst_amount']], static fn ($v) => Dec::isPositive($v)));
            foreach ($keys as $i => $k) {
                $share = $i === count($keys) - 1 ? $remaining : Dec::div(Dec::mul($e['itc_eligible_amount'], $components[$i], 6), $taxTotal, 2);
                $remaining = Dec::sub($remaining, $share);
                if (Dec::isPositive($share)) {
                    $lines[] = ['account' => $this->ledger->account('input_' . $k), 'debit' => $share, 'memo' => 'Eligible ITC ' . strtoupper($k)];
                }
            }
            $creditAcc = $e['paid_through'] === 'employee_reimbursable' ? $this->ledger->account('reimbursement_payable') : $this->ledger->account('payable');
            $lines[] = ['account' => $creditAcc, 'credit' => $e['total_amount'], 'contact_id' => $e['vendor_contact_id'] ? (int) $e['vendor_contact_id'] : null, 'employee_id' => $e['paid_by_employee_id'] ? (int) $e['paid_by_employee_id'] : null];
            $je = $this->ledger->post($e['expense_date'], 'expense', $id, 'Expense ' . $e['expense_number'] . ': ' . mb_strimwidth($e['description'], 0, 80, '…'), $lines, $e['vendor_invoice_no']);
            $this->db->table('expenses')->where('id', $id)->update(['approval_status' => 'approved', 'approved_by' => $uid, 'approved_at' => date('Y-m-d H:i:s'), 'journal_entry_id' => $je]);
            service('audit')->log('expense.approved', 'expense', $id, ['approval_status' => 'pending'], ['approval_status' => 'approved', 'itc_eligible' => $e['itc_eligible_amount']], 'Expense ' . $e['expense_number'] . ' approved');
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
    }

    public function reject(int $id, string $reason): void
    {
        $e = $this->find($id);
        if ($e['approval_status'] !== 'pending') {
            throw new BusinessRuleException('Only pending expenses can be rejected.');
        }
        $this->db->table('expenses')->where('id', $id)->update(['approval_status' => 'rejected', 'rejection_reason' => $reason, 'approved_by' => service('authz')->currentUserId(), 'approved_at' => date('Y-m-d H:i:s')]);
        service('audit')->log('expense.rejected', 'expense', $id, null, ['reason' => $reason], 'Expense ' . $e['expense_number'] . ' rejected');
    }

    /** @param array<string, mixed> $in */
    public function pay(int $id, array $in): int
    {
        $this->db->transBegin();
        try {
            $e = $this->db->query('SELECT * FROM expenses WHERE id = ? FOR UPDATE', [$id])->getRowArray() ?? throw new BusinessRuleException('Expense not found.');
            if ($e['approval_status'] !== 'approved') {
                throw new BusinessRuleException('Approve the expense before recording payment.');
            }
            $amount = Dec::round(str_replace(',', '', (string) ($in['amount'] ?? '0')));
            $due    = Dec::sub($e['total_amount'], $e['paid_amount']);
            if (! Dec::isPositive($amount) || Dec::cmp($amount, $due) > 0) {
                throw new BusinessRuleException('Payment must be between ₹0.01 and the balance due (' . inr($due) . ').');
            }
            $acc  = $this->db->table('bank_cash_accounts')->where(['id' => (int) ($in['bank_cash_account_id'] ?? 0), 'is_active' => 1])->get()->getRowArray() ?? throw new BusinessRuleException('Choose the paying account.');
            $date = ymd((string) ($in['payment_date'] ?? '')) ?? date('Y-m-d');
            $mode = in_array($in['mode'] ?? '', ReceiptService::MODES, true) ? $in['mode'] : 'bank_transfer';
            $this->db->table('expense_payments')->insert(['expense_id' => $id, 'payment_date' => $date, 'amount' => $amount, 'mode' => $mode, 'bank_cash_account_id' => $acc['id'],
                'reference_no' => trim((string) ($in['reference_no'] ?? '')) ?: null, 'created_by' => service('authz')->currentUserId()]);
            $pid = (int) $this->db->insertID();
            $debitAcc = $e['paid_through'] === 'employee_reimbursable' ? $this->ledger->account('reimbursement_payable') : $this->ledger->account('payable');
            $je = $this->ledger->post($date, 'expense_payment', $pid, 'Payment for ' . $e['expense_number'], [
                ['account' => $debitAcc, 'debit' => $amount, 'contact_id' => $e['vendor_contact_id'] ? (int) $e['vendor_contact_id'] : null, 'employee_id' => $e['paid_by_employee_id'] ? (int) $e['paid_by_employee_id'] : null],
                ['account' => (int) $acc['ledger_account_id'], 'credit' => $amount, 'bank_cash_account_id' => (int) $acc['id']],
            ], $in['reference_no'] ?? null);
            $this->db->table('expense_payments')->where('id', $pid)->update(['journal_entry_id' => $je]);
            $paid = Dec::add($e['paid_amount'], $amount);
            $this->db->table('expenses')->where('id', $id)->update([
                'paid_amount' => $paid, 'payment_status' => Dec::cmp($paid, $e['total_amount']) >= 0 ? 'paid' : 'partial',
                'reimbursement_status' => $e['paid_through'] === 'employee_reimbursable' && Dec::cmp($paid, $e['total_amount']) >= 0 ? 'reimbursed' : $e['reimbursement_status'],
            ]);
            service('audit')->log('expense.paid', 'expense', $id, null, ['amount' => $amount, 'account' => $acc['display_name']], 'Paid ' . inr($amount) . ' for ' . $e['expense_number']);
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        return $pid;
    }

    /** @return array<string, mixed> */
    public function find(int $id): array
    {
        return $this->db->table('expenses')->where('id', $id)->where('deleted_at', null)->get()->getRowArray() ?? throw new BusinessRuleException('Expense not found.');
    }
}
