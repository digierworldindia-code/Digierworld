<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRuleException;
use App\Libraries\Dec;
use App\Services\Billing\DocumentNumberAllocator;
use CodeIgniter\Database\BaseConnection;

/**
 * Customer receipts (receipt vouchers) and refunds.
 *
 * A receipt NEVER creates revenue: it moves value from Accounts Receivable
 * (or Customer Advances, when unapplied) into a cash/bank/UPI ledger:
 *   Dr Bank/Cash   amount
 *   Cr Receivable  allocated part      (settles invoices)
 *   Cr Advances    unapplied part      (applied later, or refunded)
 */
class ReceiptService
{
    public const MODES = ['cash', 'upi', 'bank_transfer', 'neft', 'rtgs', 'imps', 'cheque', 'card', 'other'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly LedgerService $ledger,
        private readonly DocumentNumberAllocator $numbers,
    ) {
    }

    /**
     * @param array{contact_id: int, receipt_date: string, amount: string, mode: string, bank_cash_account_id: int, reference_no?: string|null, cheque_date?: string|null, notes?: string|null, allocations?: list<array{document_id: int, amount: string}>, allow_duplicate?: bool} $in
     */
    public function create(array $in): int
    {
        $amount = Dec::round(str_replace(',', '', (string) ($in['amount'] ?? '0')));
        $date   = ymd((string) ($in['receipt_date'] ?? '')) ?? date('Y-m-d');
        $mode   = in_array($in['mode'] ?? '', self::MODES, true) ? $in['mode'] : null;
        $errors = [];
        if (! Dec::isPositive($amount)) {
            $errors['amount'] = 'Amount must be more than zero.';
        }
        if ($mode === null) {
            $errors['mode'] = 'Choose the payment mode.';
        }
        $contact = $this->db->table('contacts')->where('id', (int) ($in['contact_id'] ?? 0))->where('deleted_at', null)->get()->getRowArray();
        if ($contact === null) {
            $errors['contact_id'] = 'Choose the customer.';
        }
        $acc = $this->db->table('bank_cash_accounts')->where(['id' => (int) ($in['bank_cash_account_id'] ?? 0), 'is_active' => 1])->get()->getRowArray();
        if ($acc === null) {
            $errors['bank_cash_account_id'] = 'Choose the cash/bank account that received the money.';
        } elseif ($mode === 'cash' && $acc['account_kind'] !== 'cash') {
            $errors['bank_cash_account_id'] = 'Cash received goes to a cash account. Record a separate Cash → Bank deposit when it is banked.';
        } elseif ($mode !== null && $mode !== 'cash' && $acc['account_kind'] === 'cash') {
            $errors['bank_cash_account_id'] = 'Non-cash payments must go to a bank/UPI account.';
        }
        if ($date > date('Y-m-d')) {
            $errors['receipt_date'] = 'Receipt date cannot be in the future.';
        }
        if ($errors !== []) {
            throw new BusinessRuleException('Please correct the highlighted fields.', $errors);
        }

        $ref   = trim((string) ($in['reference_no'] ?? '')) ?: null;
        $hash  = hash('sha256', implode('|', [$contact['id'], $date, $amount, $mode, strtolower((string) $ref)]));
        if (empty($in['allow_duplicate'])) {
            $dupe = $this->db->table('payment_receipts')->where(['dedupe_hash' => $hash, 'status' => 'active'])->get()->getRowArray();
            if ($dupe !== null) {
                throw new BusinessRuleException('A receipt with the same customer, date, amount, mode and reference already exists (' . $dupe['receipt_number'] . '). Tick "This is a separate payment" if it is genuinely another payment.', ['duplicate' => $dupe['receipt_number']]);
            }
            if ($ref !== null) {
                $refDupe = $this->db->table('payment_receipts')->where(['reference_no' => $ref, 'status' => 'active', 'bank_cash_account_id' => $acc['id']])->get()->getRowArray();
                if ($refDupe !== null) {
                    throw new BusinessRuleException('Reference/UTR ' . $ref . ' was already used on receipt ' . $refDupe['receipt_number'] . '. Tick "This is a separate payment" to proceed.', ['duplicate' => $refDupe['receipt_number']]);
                }
            }
        }

        // Nested calls (e.g. cash memo 'paid at counter') join the caller's transaction.
        $this->db->transBegin();
        try {
            $allocs    = $this->validateAllocations((int) $contact['id'], (array) ($in['allocations'] ?? []), $amount);
            $allocated = Dec::add('0', ...array_column($allocs, 'amount'));
            $unapplied = Dec::sub($amount, $allocated);
            $num       = $this->numbers->allocate('receipt', $date);
            $this->db->table('payment_receipts')->insert([
                'receipt_type'         => 'receipt',
                'receipt_number'       => $num['formatted'],
                'sequence_id'          => $num['sequence_id'],
                'sequence_number'      => $num['number'],
                'financial_year_id'    => $num['financial_year_id'],
                'contact_id'           => $contact['id'],
                'receipt_date'         => $date,
                'amount'               => $amount,
                'mode'                 => $mode,
                'bank_cash_account_id' => $acc['id'],
                'reference_no'         => $ref,
                'cheque_date'          => $mode === 'cheque' ? ymd((string) ($in['cheque_date'] ?? '')) : null,
                'cheque_status'        => $mode === 'cheque' ? 'pending' : 'na',
                'allocated_amount'     => $allocated,
                'unapplied_amount'     => $unapplied,
                'requires_review'      => (int) $acc['requires_review'],
                'dedupe_hash'          => $hash,
                'notes'                => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 500) ?: null,
                'created_by'           => service('authz')->currentUserId(),
            ]);
            $rid = (int) $this->db->insertID();
            foreach ($allocs as $a) {
                $this->db->table('payment_allocations')->insert(['receipt_id' => $rid, 'document_id' => $a['document_id'], 'amount' => $a['amount'], 'allocated_by' => service('authz')->currentUserId()]);
            }
            $lines = [
                ['account' => (int) $acc['ledger_account_id'], 'debit' => $amount, 'bank_cash_account_id' => (int) $acc['id'], 'contact_id' => (int) $contact['id'], 'memo' => 'Receipt ' . $num['formatted'] . ' (' . strtoupper($mode) . ')'],
                ['account' => $this->ledger->account('receivable'), 'credit' => $allocated, 'contact_id' => (int) $contact['id']],
                ['account' => $this->ledger->account('customer_advance'), 'credit' => $unapplied, 'contact_id' => (int) $contact['id'], 'memo' => 'Unapplied / advance'],
            ];
            $je = $this->ledger->post($date, 'payment_receipt', $rid, 'Receipt ' . $num['formatted'] . ' from ' . $contact['name'], $lines, $ref);
            $this->db->table('payment_receipts')->where('id', $rid)->update(['journal_entry_id' => $je]);
            foreach ($allocs as $a) {
                service('billing')->recalcPayment((int) $a['document_id']);
            }
            service('audit')->log('receipt.created', 'payment_receipt', $rid, null, [
                'number' => $num['formatted'], 'amount' => $amount, 'mode' => $mode, 'account' => $acc['display_name'],
                'allocated' => $allocated, 'unapplied' => $unapplied, 'documents' => array_column($allocs, 'document_id'),
            ], 'Receipt ' . $num['formatted'] . ' ' . inr($amount) . ($acc['ownership'] === 'owner_personal' ? ' into owner personal account (flagged for review)' : ''));
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }


        return $rid;
    }

    /**
     * @param list<array{document_id: int|string, amount: string}> $raw
     *
     * @return list<array{document_id: int, amount: string}>
     */
    private function validateAllocations(int $contactId, array $raw, string $limit): array
    {
        $out   = [];
        $total = '0';
        foreach ($raw as $a) {
            $amt = Dec::round(str_replace(',', '', (string) ($a['amount'] ?? '0')));
            $did = (int) ($a['document_id'] ?? 0);
            if ($did <= 0 || ! Dec::isPositive($amt)) {
                continue;
            }
            $doc = $this->db->query('SELECT id, contact_id, status, doc_type, document_number, balance_due FROM billing_documents WHERE id = ? FOR UPDATE', [$did])->getRowArray();
            if ($doc === null || $doc['status'] !== 'finalized' || ! in_array($doc['doc_type'], ['tax_invoice', 'bill_of_supply', 'cash_memo', 'debit_note'], true)) {
                throw new BusinessRuleException('Payments can only be applied to finalized invoices, cash memos, bills of supply or debit notes.');
            }
            if ((int) $doc['contact_id'] !== $contactId) {
                throw new BusinessRuleException('Document ' . $doc['document_number'] . ' belongs to a different customer.');
            }
            if (Dec::cmp($amt, $doc['balance_due']) > 0) {
                throw new BusinessRuleException('Allocation of ' . inr($amt) . ' exceeds the balance due on ' . $doc['document_number'] . ' (' . inr($doc['balance_due']) . '). Keep the excess as an advance instead.');
            }
            $total = Dec::add($total, $amt);
            $out[] = ['document_id' => $did, 'amount' => $amt];
        }
        if (Dec::cmp($total, $limit) > 0) {
            throw new BusinessRuleException('Allocations (' . inr($total) . ') exceed the amount available (' . inr($limit) . ').');
        }

        return $out;
    }

    /** Apply part of an unapplied receipt (advance) to invoices: Dr Advances / Cr Receivable. */
    public function applyAdvance(int $receiptId, array $allocations): void
    {
        $this->db->transBegin();
        try {
            $r = $this->db->query('SELECT * FROM payment_receipts WHERE id = ? FOR UPDATE', [$receiptId])->getRowArray() ?? throw new BusinessRuleException('Receipt not found.');
            if ($r['status'] !== 'active' || $r['receipt_type'] !== 'receipt') {
                throw new BusinessRuleException('Only active receipts can be applied.');
            }
            $allocs = $this->validateAllocations((int) $r['contact_id'], $allocations, $r['unapplied_amount']);
            if ($allocs === []) {
                throw new BusinessRuleException('Enter at least one amount to apply.');
            }
            $total = Dec::add('0', ...array_column($allocs, 'amount'));
            $date  = date('Y-m-d');
            $je = $this->ledger->post($date, 'payment_allocation', $receiptId, 'Advance ' . $r['receipt_number'] . ' applied to invoices', [
                ['account' => $this->ledger->account('customer_advance'), 'debit' => $total, 'contact_id' => (int) $r['contact_id']],
                ['account' => $this->ledger->account('receivable'), 'credit' => $total, 'contact_id' => (int) $r['contact_id']],
            ], $r['receipt_number']);
            foreach ($allocs as $a) {
                $this->db->table('payment_allocations')->insert(['receipt_id' => $receiptId, 'document_id' => $a['document_id'], 'amount' => $a['amount'], 'allocated_by' => service('authz')->currentUserId(), 'journal_entry_id' => $je]);
                service('billing')->recalcPayment((int) $a['document_id']);
            }
            $this->db->table('payment_receipts')->where('id', $receiptId)->update([
                'allocated_amount' => Dec::add($r['allocated_amount'], $total), 'unapplied_amount' => Dec::sub($r['unapplied_amount'], $total),
            ]);
            service('audit')->log('receipt.applied', 'payment_receipt', $receiptId, ['unapplied' => $r['unapplied_amount']], ['applied' => $total, 'documents' => array_column($allocs, 'document_id')], 'Advance applied ' . inr($total));
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
    }

    /** Void a receipt: reverse its journal (and later allocation journals) and release allocations. */
    public function void(int $receiptId, string $reason): void
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleException('Give a reason for voiding.');
        }
        $this->db->transBegin();
        try {
            $r = $this->db->query('SELECT * FROM payment_receipts WHERE id = ? FOR UPDATE', [$receiptId])->getRowArray() ?? throw new BusinessRuleException('Receipt not found.');
            if ($r['status'] !== 'active') {
                throw new BusinessRuleException('Receipt is already void.');
            }
            if ($this->db->table('payment_receipts')->where(['refund_of_receipt_id' => $receiptId, 'status' => 'active'])->countAllResults() > 0) {
                throw new BusinessRuleException('Void the refunds made from this receipt first.');
            }
            if ($this->db->table('reconciliation_matches')->where(['match_type' => 'receipt', 'match_id' => $receiptId, 'status' => 'confirmed'])->countAllResults() > 0) {
                throw new BusinessRuleException('This receipt is reconciled with a bank statement line. Unmatch it in Bank Reconciliation first.');
            }
            $date = date('Y-m-d');
            $this->ledger->reverse((int) $r['journal_entry_id'], max($date, $r['receipt_date']), 'Void receipt ' . $r['receipt_number'] . ': ' . $reason);
            $allocs = $this->db->table('payment_allocations')->where(['receipt_id' => $receiptId, 'reversed_at' => null])->get()->getResultArray();
            $seenJe = [];
            foreach ($allocs as $a) {
                if ($a['journal_entry_id'] !== null && ! isset($seenJe[$a['journal_entry_id']])) {
                    $seenJe[$a['journal_entry_id']] = true;
                    $this->ledger->reverse((int) $a['journal_entry_id'], $date, 'Void receipt ' . $r['receipt_number']);
                }
                $this->db->table('payment_allocations')->where('id', $a['id'])->update(['reversed_at' => date('Y-m-d H:i:s'), 'reversed_by' => service('authz')->currentUserId()]);
            }
            $this->db->table('payment_receipts')->where('id', $receiptId)->update(['status' => 'void', 'void_reason' => $reason, 'voided_by' => service('authz')->currentUserId(), 'voided_at' => date('Y-m-d H:i:s')]);
            foreach (array_unique(array_column($allocs, 'document_id')) as $docId) {
                service('billing')->recalcPayment((int) $docId);
            }
            service('audit')->log('receipt.voided', 'payment_receipt', $receiptId, ['status' => 'active'], ['status' => 'void', 'reason' => $reason], 'Receipt ' . $r['receipt_number'] . ' voided');
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
    }

    /** Cheque cleared, or bounced (bounce voids the receipt and re-opens the invoices). */
    public function chequeStatus(int $receiptId, string $status, string $note = ''): void
    {
        $r = $this->db->table('payment_receipts')->where('id', $receiptId)->get()->getRowArray() ?? throw new BusinessRuleException('Receipt not found.');
        if ($r['mode'] !== 'cheque' || $r['cheque_status'] !== 'pending' || $r['status'] !== 'active') {
            throw new BusinessRuleException('Only active receipts with a pending cheque can be updated.');
        }
        if ($status === 'cleared') {
            $this->db->table('payment_receipts')->where('id', $receiptId)->update(['cheque_status' => 'cleared']);
            service('audit')->log('receipt.cheque_cleared', 'payment_receipt', $receiptId, ['cheque_status' => 'pending'], ['cheque_status' => 'cleared'], 'Cheque cleared on ' . $r['receipt_number']);

            return;
        }
        if ($status !== 'bounced') {
            throw new BusinessRuleException('Unknown cheque status.');
        }
        $this->db->transBegin();
        try {
            $this->void($receiptId, 'Cheque bounced' . ($note !== '' ? ': ' . $note : ''));
            $this->db->table('payment_receipts')->where('id', $receiptId)->update(['cheque_status' => 'bounced']);
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
    }

    /**
     * Refund money to a customer from an advance (unapplied receipt) or from a
     * credit balance created by credit notes.
     */
    public function refund(int $contactId, string $amount, string $date, int $accountId, string $mode, ?int $fromReceiptId, string $notes): int
    {
        $amount = Dec::round($amount);
        if (! Dec::isPositive($amount)) {
            throw new BusinessRuleException('Refund amount must be more than zero.');
        }
        $acc = $this->db->table('bank_cash_accounts')->where(['id' => $accountId, 'is_active' => 1])->get()->getRowArray() ?? throw new BusinessRuleException('Choose the account the refund is paid from.');
        $this->db->transBegin();
        try {
            if ($fromReceiptId !== null) {
                $src = $this->db->query('SELECT * FROM payment_receipts WHERE id = ? FOR UPDATE', [$fromReceiptId])->getRowArray();
                if ($src === null || (int) $src['contact_id'] !== $contactId || $src['status'] !== 'active') {
                    throw new BusinessRuleException('Choose an active receipt of this customer.');
                }
                if (Dec::cmp($amount, $src['unapplied_amount']) > 0) {
                    throw new BusinessRuleException('Refund exceeds the unapplied balance of ' . $src['receipt_number'] . ' (' . inr($src['unapplied_amount']) . ').');
                }
                $debitAcc = $this->ledger->account('customer_advance');
                $this->db->table('payment_receipts')->where('id', $fromReceiptId)->update(['unapplied_amount' => Dec::sub($src['unapplied_amount'], $amount)]);
            } else {
                $credit = Dec::neg($this->ledger->balance($this->ledger->account('receivable'), null, $contactId));
                if (Dec::cmp($amount, $credit) > 0) {
                    throw new BusinessRuleException('This customer has no credit balance large enough for this refund (available ' . inr(Dec::max($credit, '0')) . '). Issue a credit note first.');
                }
                $debitAcc = $this->ledger->account('receivable');
            }
            $num = $this->numbers->allocate('refund', $date);
            $this->db->table('payment_receipts')->insert([
                'receipt_type' => 'refund', 'receipt_number' => $num['formatted'], 'sequence_id' => $num['sequence_id'], 'sequence_number' => $num['number'],
                'financial_year_id' => $num['financial_year_id'], 'contact_id' => $contactId, 'receipt_date' => $date, 'amount' => $amount, 'mode' => $mode,
                'bank_cash_account_id' => $accountId, 'refund_of_receipt_id' => $fromReceiptId, 'allocated_amount' => '0.00', 'unapplied_amount' => '0.00',
                'notes' => mb_substr($notes, 0, 500), 'created_by' => service('authz')->currentUserId(),
            ]);
            $rid = (int) $this->db->insertID();
            $je  = $this->ledger->post($date, 'refund', $rid, 'Refund ' . $num['formatted'], [
                ['account' => $debitAcc, 'debit' => $amount, 'contact_id' => $contactId],
                ['account' => (int) $acc['ledger_account_id'], 'credit' => $amount, 'bank_cash_account_id' => $accountId, 'contact_id' => $contactId],
            ]);
            $this->db->table('payment_receipts')->where('id', $rid)->update(['journal_entry_id' => $je]);
            service('audit')->log('refund.created', 'payment_receipt', $rid, null, ['amount' => $amount, 'from_receipt' => $fromReceiptId], 'Refund ' . $num['formatted'] . ' ' . inr($amount));
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        return $rid;
    }

    /** @return list<array<string, mixed>> open sale documents for a customer */
    public function openDocuments(int $contactId): array
    {
        return $this->db->table('billing_documents')->select('id, doc_type, document_number, issue_date, due_date, grand_total, balance_due')
            ->where(['contact_id' => $contactId, 'status' => 'finalized'])->whereIn('doc_type', ['tax_invoice', 'bill_of_supply', 'cash_memo', 'debit_note'])
            ->where('balance_due >', 0)->orderBy('issue_date')->get()->getResultArray();
    }
}
