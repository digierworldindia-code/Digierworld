<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRuleException;
use App\Libraries\Dec;
use App\Services\Billing\DocumentNumberAllocator;
use CodeIgniter\Database\BaseConnection;

/**
 * Internal movement between the company's own cash/bank/UPI accounts
 * (e.g. depositing collected cash into the bank). It is NOT income and has
 * no GST: Dr destination ledger / Cr source ledger.
 */
class TransferService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly LedgerService $ledger,
        private readonly DocumentNumberAllocator $numbers,
    ) {
    }

    /** @param array<string, mixed> $in */
    public function create(array $in): int
    {
        $from   = $this->db->table('bank_cash_accounts')->where(['id' => (int) ($in['from_account_id'] ?? 0), 'is_active' => 1])->get()->getRowArray();
        $to     = $this->db->table('bank_cash_accounts')->where(['id' => (int) ($in['to_account_id'] ?? 0), 'is_active' => 1])->get()->getRowArray();
        $amount = Dec::round(str_replace(',', '', (string) ($in['amount'] ?? '0')));
        $date   = ymd((string) ($in['transfer_date'] ?? '')) ?? date('Y-m-d');
        $errors = [];
        if ($from === null) {
            $errors['from_account_id'] = 'Choose the account money leaves.';
        }
        if ($to === null) {
            $errors['to_account_id'] = 'Choose the account money arrives in.';
        }
        if ($from && $to && $from['id'] === $to['id']) {
            $errors['to_account_id'] = 'From and To accounts must differ.';
        }
        if (! Dec::isPositive($amount)) {
            $errors['amount'] = 'Amount must be more than zero.';
        }
        if ($date > date('Y-m-d')) {
            $errors['transfer_date'] = 'Date cannot be in the future.';
        }
        if ($errors !== []) {
            throw new BusinessRuleException('Please correct the highlighted fields.', $errors);
        }
        if ($from['account_kind'] === 'cash' && empty($in['allow_negative'])) {
            $bal = $this->ledger->bankBalance((int) $from['id'], $date);
            if (Dec::cmp($amount, $bal) > 0) {
                throw new BusinessRuleException('Cash in hand on ' . dmy($date) . ' is only ' . inr($bal) . '. Record the cash receipts first, or confirm to proceed.', ['amount' => 'exceeds cash balance']);
            }
        }
        $kind = match (true) {
            $from['account_kind'] === 'cash' && $to['account_kind'] !== 'cash' => 'cash_deposit',
            $from['account_kind'] !== 'cash' && $to['account_kind'] === 'cash' => 'cash_withdrawal',
            $from['account_kind'] === 'upi_clearing'                           => 'upi_settlement',
            $from['ownership'] === 'owner_personal'                            => 'owner_clearing_settlement',
            default                                                            => 'bank_transfer',
        };

        $this->db->transBegin();
        try {
            $no = $this->numbers->allocate('transfer', $date)['formatted'];
            $this->db->table('money_transfers')->insert([
                'transfer_number' => $no, 'transfer_date' => $date, 'from_account_id' => $from['id'], 'to_account_id' => $to['id'],
                'amount' => $amount, 'transfer_kind' => $kind, 'reference_no' => trim((string) ($in['reference_no'] ?? '')) ?: null,
                'notes' => trim((string) ($in['notes'] ?? '')) ?: null, 'created_by' => service('authz')->currentUserId(),
            ]);
            $id = (int) $this->db->insertID();
            $je = $this->ledger->post($date, 'money_transfer', $id, 'Transfer ' . $no . ': ' . $from['display_name'] . ' → ' . $to['display_name'] . ' (not income)', [
                ['account' => (int) $to['ledger_account_id'], 'debit' => $amount, 'bank_cash_account_id' => (int) $to['id']],
                ['account' => (int) $from['ledger_account_id'], 'credit' => $amount, 'bank_cash_account_id' => (int) $from['id']],
            ], $in['reference_no'] ?? null);
            $this->db->table('money_transfers')->where('id', $id)->update(['journal_entry_id' => $je]);
            service('audit')->log('transfer.created', 'money_transfer', $id, null, ['number' => $no, 'from' => $from['display_name'], 'to' => $to['display_name'], 'amount' => $amount, 'kind' => $kind], 'Transfer ' . $no . ' ' . inr($amount));
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        return $id;
    }

    public function void(int $id, string $reason): void
    {
        $this->db->transBegin();
        try {
            $t = $this->db->query('SELECT * FROM money_transfers WHERE id = ? FOR UPDATE', [$id])->getRowArray() ?? throw new BusinessRuleException('Transfer not found.');
            if ($t['status'] !== 'active') {
                throw new BusinessRuleException('Transfer is already void.');
            }
            if ($this->db->table('reconciliation_matches')->whereIn('match_type', ['transfer_in', 'transfer_out'])->where(['match_id' => $id, 'status' => 'confirmed'])->countAllResults() > 0) {
                throw new BusinessRuleException('This transfer is reconciled with a bank statement line. Unmatch it first.');
            }
            $this->ledger->reverse((int) $t['journal_entry_id'], max(date('Y-m-d'), $t['transfer_date']), 'Void transfer: ' . $reason);
            $this->db->table('money_transfers')->where('id', $id)->update(['status' => 'void', 'void_reason' => $reason, 'voided_by' => service('authz')->currentUserId(), 'voided_at' => date('Y-m-d H:i:s')]);
            service('audit')->log('transfer.voided', 'money_transfer', $id, ['status' => 'active'], ['status' => 'void', 'reason' => $reason], 'Transfer ' . $t['transfer_number'] . ' voided');
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
    }
}
