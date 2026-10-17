<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRuleException;
use App\Libraries\Dec;
use CodeIgniter\Database\BaseConnection;

/**
 * Bank statement import and matching against book entries. Matching never
 * changes accounting: it only links a statement line to receipts, refunds,
 * transfers, expense/salary payments or journals already in the books.
 */
class ReconciliationService
{
    /** match_type => [table, amount column, date column, account column, label] */
    private const TARGETS = [
        'receipt'         => ['payment_receipts', 'amount', 'receipt_date', 'bank_cash_account_id', 'Receipt'],
        'refund'          => ['payment_receipts', 'amount', 'receipt_date', 'bank_cash_account_id', 'Refund'],
        'transfer_in'     => ['money_transfers', 'amount', 'transfer_date', 'to_account_id', 'Transfer in'],
        'transfer_out'    => ['money_transfers', 'amount', 'transfer_date', 'from_account_id', 'Transfer out'],
        'expense_payment' => ['expense_payments', 'amount', 'payment_date', 'bank_cash_account_id', 'Expense payment'],
        'salary_payment'  => ['payroll_payments', 'amount', 'payment_date', 'bank_cash_account_id', 'Salary payment'],
    ];

    public function __construct(private readonly BaseConnection $db, private readonly LedgerService $ledger)
    {
    }

    /**
     * Store parsed lines; duplicates (same account + content hash) are skipped.
     *
     * @param list<array<string, mixed>> $lines
     *
     * @return array{import_id: int, imported: int, duplicates: int}
     */
    public function import(int $accountId, int $fileId, string $originalName, array $lines, int $errorCount = 0): array
    {
        $acc = $this->db->table('bank_cash_accounts')->where(['id' => $accountId, 'is_active' => 1])->get()->getRowArray();
        if ($acc === null || $acc['account_kind'] === 'cash') {
            throw new BusinessRuleException('Choose an active bank/UPI account for the statement.');
        }
        if ($lines === []) {
            throw new BusinessRuleException('No transactions were found in the file. Check the column mapping.');
        }
        $dates = array_column($lines, 'txn_date');
        $this->db->transBegin();
        try {
            $this->db->table('bank_imports')->insert([
                'bank_cash_account_id' => $accountId, 'stored_file_id' => $fileId, 'original_name' => mb_substr($originalName, 0, 255),
                'statement_from' => min($dates), 'statement_to' => max($dates), 'rows_total' => count($lines) + $errorCount,
                'rows_imported' => 0, 'rows_duplicate' => 0, 'rows_error' => $errorCount,
                'imported_by' => service('authz')->currentUserId(), 'imported_at' => date('Y-m-d H:i:s'),
            ]);
            $importId = (int) $this->db->insertID();
            $imported = 0;
            $dupes    = 0;
            $seen     = [];
            foreach ($lines as $l) {
                // Two genuinely identical lines in one file (same day, amount, narration, balance)
                // are kept apart by their order within the file.
                $base = implode('|', [$accountId, $l['txn_date'], mb_strtolower(trim((string) $l['description'])), (string) $l['reference'], $l['debit'], $l['credit'], (string) $l['balance']]);
                $seen[$base] = ($seen[$base] ?? 0) + 1;
                $hash = hash('sha256', $base . '|' . $seen[$base]);
                if ($this->db->table('bank_import_lines')->where(['bank_cash_account_id' => $accountId, 'line_hash' => $hash])->countAllResults() > 0) {
                    $dupes++;

                    continue;
                }
                $this->db->table('bank_import_lines')->insert([
                    'bank_import_id' => $importId, 'bank_cash_account_id' => $accountId, 'txn_date' => $l['txn_date'], 'value_date' => $l['value_date'] ?? null,
                    'description' => (string) $l['description'], 'reference' => $l['reference'] ?? null, 'debit' => $l['debit'], 'credit' => $l['credit'],
                    'balance' => $l['balance'] ?? null, 'line_hash' => $hash, 'match_status' => 'unmatched', 'matched_amount' => '0.00',
                ]);
                $imported++;
            }
            $this->db->table('bank_imports')->where('id', $importId)->update(['rows_imported' => $imported, 'rows_duplicate' => $dupes]);
            service('audit')->log('bank.statement_imported', 'bank_import', $importId, null, ['account' => $acc['display_name'], 'imported' => $imported, 'duplicates' => $dupes, 'errors' => $errorCount],
                "Statement imported for {$acc['display_name']}: {$imported} new, {$dupes} duplicate");
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        return ['import_id' => $importId, 'imported' => $imported, 'duplicates' => $dupes];
    }

    /**
     * Candidate book entries for a statement line, best first.
     *
     * @return list<array{type: string, id: int, label: string, number: string, date: string, amount: string, party: string, score: int}>
     */
    public function suggestions(array $line, int $limit = 5): array
    {
        $isCredit = Dec::isPositive($line['credit']);
        $amount   = $isCredit ? $line['credit'] : $line['debit'];
        $types    = $isCredit ? ['receipt', 'transfer_in'] : ['expense_payment', 'refund', 'transfer_out', 'salary_payment'];
        $out      = [];
        foreach ($types as $type) {
            foreach ($this->openTargets($type, (int) $line['bank_cash_account_id'], $line['txn_date'], 20) as $t) {
                $score = 0;
                if (Dec::cmp($t['amount'], $amount) === 0) {
                    $score += 60;
                } elseif (Dec::cmp($t['amount'], $amount) < 0) {
                    $score += 15; // could be one of several entries batched into one line
                } else {
                    continue;
                }
                $days  = abs((strtotime($t['date']) - strtotime($line['txn_date'])) / 86400);
                $score += $days === 0.0 ? 25 : ($days <= 2 ? 18 : ($days <= 5 ? 10 : 3));
                $desc  = strtoupper($line['description'] . ' ' . $line['reference']);
                if ($t['reference'] !== '' && strlen($t['reference']) >= 4 && str_contains($desc, strtoupper($t['reference']))) {
                    $score += 30;
                }
                foreach (preg_split('/\s+/', strtoupper($t['party'])) ?: [] as $word) {
                    if (strlen($word) >= 4 && str_contains($desc, $word)) {
                        $score += 8;
                        break;
                    }
                }
                $out[] = $t + ['score' => min($score, 100)];
            }
        }
        usort($out, static fn ($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['date'], $b['date']));

        return array_slice($out, 0, $limit);
    }

    /** @return list<array<string, mixed>> unmatched book entries of one kind for the account around a date */
    public function openTargets(string $type, int $accountId, ?string $near = null, int $windowDays = 0): array
    {
        [$table, $amt, $date, $accCol] = self::TARGETS[$type];
        $b = $this->db->table($table . ' t')->where("t.{$accCol}", $accountId)->whereIn('t.reconciliation_status', ['unmatched', 'partial']);
        switch ($type) {
            case 'receipt':
            case 'refund':
                $b->select("t.id, t.receipt_number AS number, t.{$date} AS date, t.{$amt} AS amount, COALESCE(t.reference_no,'') AS reference, c.name AS party")
                    ->join('contacts c', 'c.id = t.contact_id')->where(['t.status' => 'active', 't.receipt_type' => $type]);
                break;
            case 'transfer_in':
            case 'transfer_out':
                $b->select("t.id, t.transfer_number AS number, t.{$date} AS date, t.{$amt} AS amount, COALESCE(t.reference_no,'') AS reference, '' AS party")->where('t.status', 'active');
                break;
            case 'expense_payment':
                $b->select("t.id, e.expense_number AS number, t.{$date} AS date, t.{$amt} AS amount, COALESCE(t.reference_no,'') AS reference, COALESCE(c.name, e.description) AS party")
                    ->join('expenses e', 'e.id = t.expense_id')->join('contacts c', 'c.id = e.vendor_contact_id', 'left')->where('t.status', 'active');
                break;
            case 'salary_payment':
                $b->select("t.id, CONCAT('PAY-', t.payroll_run_id) AS number, t.{$date} AS date, t.{$amt} AS amount, COALESCE(t.reference_no,'') AS reference, '' AS party");
                break;
        }
        if ($near !== null && $windowDays > 0) {
            $b->where("t.{$date} >=", date('Y-m-d', strtotime($near . " -{$windowDays} days")))->where("t.{$date} <=", date('Y-m-d', strtotime($near . " +{$windowDays} days")));
        }
        $rows = $b->orderBy("t.{$date}")->limit(200)->get()->getResultArray();
        $label = self::TARGETS[$type][4];
        foreach ($rows as &$r) {
            $r = ['type' => $type, 'id' => (int) $r['id'], 'label' => $label, 'number' => (string) $r['number'], 'date' => $r['date'],
                'amount' => $r['amount'], 'reference' => (string) $r['reference'], 'party' => (string) $r['party']];
        }

        return $rows;
    }

    /**
     * Confirm a line against one or more book entries whose total equals the line.
     *
     * @param list<array{type: string, id: int}> $targets
     */
    public function confirm(int $lineId, array $targets): void
    {
        if ($targets === []) {
            throw new BusinessRuleException('Select at least one book entry.');
        }
        $this->db->transBegin();
        try {
            $line = $this->db->query('SELECT * FROM bank_import_lines WHERE id = ? FOR UPDATE', [$lineId])->getRowArray() ?? throw new BusinessRuleException('Statement line not found.');
            if ($line['match_status'] !== 'unmatched') {
                throw new BusinessRuleException('This line is already ' . $line['match_status'] . '.');
            }
            $isCredit = Dec::isPositive($line['credit']);
            $amount   = $isCredit ? $line['credit'] : $line['debit'];
            $allowed  = $isCredit ? ['receipt', 'transfer_in'] : ['expense_payment', 'refund', 'transfer_out', 'salary_payment'];
            $sum      = '0';
            $rows     = [];
            foreach ($targets as $t) {
                $type = (string) $t['type'];
                if (! in_array($type, $allowed, true)) {
                    throw new BusinessRuleException(($isCredit ? 'Money in' : 'Money out') . ' cannot be matched to a ' . (self::TARGETS[$type][4] ?? $type) . '.');
                }
                [$table, $amt, , $accCol] = self::TARGETS[$type];
                $row = $this->db->query("SELECT * FROM {$table} WHERE id = ? FOR UPDATE", [(int) $t['id']])->getRowArray();
                if ($row === null || (int) $row[$accCol] !== (int) $line['bank_cash_account_id']) {
                    throw new BusinessRuleException('The selected entry belongs to a different account.');
                }
                if (($row['status'] ?? 'active') !== 'active' || $row['reconciliation_status'] === 'matched') {
                    throw new BusinessRuleException('The selected entry is void or already reconciled.');
                }
                $sum    = Dec::add($sum, $row[$amt]);
                $rows[] = [$type, $table, (int) $row['id'], $row[$amt]];
            }
            if (Dec::cmp($sum, $amount) !== 0) {
                throw new BusinessRuleException('Selected entries total ' . inr($sum) . ' but the statement line is ' . inr($amount) . '. Totals must be equal.');
            }
            foreach ($rows as [$type, $table, $id, $amt]) {
                $this->db->table('reconciliation_matches')->insert([
                    'bank_import_line_id' => $lineId, 'match_type' => $type, 'match_id' => $id, 'amount' => $amt, 'confidence' => '100.00',
                    'status' => 'confirmed', 'confirmed_by' => service('authz')->currentUserId(), 'confirmed_at' => date('Y-m-d H:i:s'),
                ]);
                $this->db->table($table)->where('id', $id)->update(['reconciliation_status' => 'matched']);
            }
            $this->db->table('bank_import_lines')->where('id', $lineId)->update(['match_status' => 'matched', 'matched_amount' => $amount]);
            service('audit')->log('bank.line_matched', 'bank_import_line', $lineId, null, ['targets' => $targets], 'Statement line ' . dmy($line['txn_date']) . ' ' . inr($amount) . ' matched');
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
    }

    public function unmatch(int $lineId): void
    {
        $this->db->transBegin();
        try {
            $line = $this->db->query('SELECT * FROM bank_import_lines WHERE id = ? FOR UPDATE', [$lineId])->getRowArray() ?? throw new BusinessRuleException('Statement line not found.');
            $matches = $this->db->table('reconciliation_matches')->where(['bank_import_line_id' => $lineId, 'status' => 'confirmed'])->get()->getResultArray();
            foreach ($matches as $m) {
                if ($m['match_type'] !== 'journal') {
                    $this->db->table(self::TARGETS[$m['match_type']][0])->where('id', $m['match_id'])->update(['reconciliation_status' => 'unmatched']);
                }
                $this->db->table('reconciliation_matches')->where('id', $m['id'])->update(['status' => 'rejected']);
            }
            $this->db->table('bank_import_lines')->where('id', $lineId)->update(['match_status' => 'unmatched', 'matched_amount' => '0.00']);
            service('audit')->log('bank.line_unmatched', 'bank_import_line', $lineId, ['status' => $line['match_status']], ['status' => 'unmatched'], 'Statement line unmatched');
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
    }

    public function ignore(int $lineId, string $reason): void
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleException('Say why this line needs no book entry.');
        }
        $line = $this->db->table('bank_import_lines')->where('id', $lineId)->get()->getRowArray() ?? throw new BusinessRuleException('Statement line not found.');
        if ($line['match_status'] !== 'unmatched') {
            throw new BusinessRuleException('Only unmatched lines can be ignored.');
        }
        $this->db->table('bank_import_lines')->where('id', $lineId)->update(['match_status' => 'ignored']);
        service('audit')->log('bank.line_ignored', 'bank_import_line', $lineId, null, ['reason' => $reason], 'Statement line ignored: ' . $reason);
    }

    /**
     * Book a bank charge / interest straight from a statement line and match it.
     * Debit line: Dr chosen expense ledger / Cr bank. Credit line: Dr bank / Cr chosen income ledger.
     */
    public function bookFromLine(int $lineId, int $ledgerAccountId, string $narration): int
    {
        $this->db->transBegin();
        try {
            $line = $this->db->query('SELECT * FROM bank_import_lines WHERE id = ? FOR UPDATE', [$lineId])->getRowArray() ?? throw new BusinessRuleException('Statement line not found.');
            if ($line['match_status'] !== 'unmatched') {
                throw new BusinessRuleException('This line is already ' . $line['match_status'] . '.');
            }
            $ledger = $this->db->table('ledger_accounts')->where(['id' => $ledgerAccountId, 'is_active' => 1])->get()->getRowArray();
            $isCredit = Dec::isPositive($line['credit']);
            if ($ledger === null || $ledger['account_group'] !== ($isCredit ? 'income' : 'expense')) {
                throw new BusinessRuleException($isCredit ? 'Choose an income ledger (e.g. interest received).' : 'Choose an expense ledger (e.g. bank charges).');
            }
            $amount = $isCredit ? $line['credit'] : $line['debit'];
            $bankLedger = $this->ledger->bankLedger((int) $line['bank_cash_account_id']);
            $lines = $isCredit
                ? [['account' => $bankLedger, 'debit' => $amount, 'bank_cash_account_id' => (int) $line['bank_cash_account_id']], ['account' => $ledgerAccountId, 'credit' => $amount]]
                : [['account' => $ledgerAccountId, 'debit' => $amount], ['account' => $bankLedger, 'credit' => $amount, 'bank_cash_account_id' => (int) $line['bank_cash_account_id']]];
            $je = $this->ledger->post($line['txn_date'], 'bank_statement', $lineId, mb_substr($narration !== '' ? $narration : ($ledger['name'] . ' — ' . $line['description']), 0, 255), $lines, $line['reference']);
            $this->db->table('reconciliation_matches')->insert([
                'bank_import_line_id' => $lineId, 'match_type' => 'journal', 'match_id' => $je, 'amount' => $amount, 'confidence' => '100.00',
                'status' => 'confirmed', 'confirmed_by' => service('authz')->currentUserId(), 'confirmed_at' => date('Y-m-d H:i:s'),
            ]);
            $this->db->table('bank_import_lines')->where('id', $lineId)->update(['match_status' => 'matched', 'matched_amount' => $amount]);
            service('audit')->log('bank.line_booked', 'bank_import_line', $lineId, null, ['journal' => $je, 'ledger' => $ledger['name']], 'Booked ' . inr($amount) . ' to ' . $ledger['name'] . ' from statement');
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        return $je;
    }

    /**
     * Statement vs books at the last imported date.
     *
     * @return array{statement_date: ?string, statement_balance: ?string, book_balance: ?string, difference: ?string, unmatched_lines: int, unmatched_book: int}
     */
    public function summary(int $accountId): array
    {
        $last = $this->db->table('bank_import_lines')->where('bank_cash_account_id', $accountId)->where('balance IS NOT NULL', null, false)
            ->orderBy('txn_date', 'DESC')->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        $date = $last['txn_date'] ?? null;
        $book = $date ? $this->ledger->bankBalance($accountId, $date) : null;
        $unmatchedBook = 0;
        foreach (['receipt', 'refund', 'transfer_in', 'transfer_out', 'expense_payment', 'salary_payment'] as $t) {
            $unmatchedBook += count($this->openTargets($t, $accountId));
        }

        return [
            'statement_date'    => $date,
            'statement_balance' => $last['balance'] ?? null,
            'book_balance'      => $book,
            'difference'        => $book !== null && isset($last['balance']) ? Dec::sub($last['balance'], $book) : null,
            'unmatched_lines'   => $this->db->table('bank_import_lines')->where(['bank_cash_account_id' => $accountId, 'match_status' => 'unmatched'])->countAllResults(),
            'unmatched_book'    => $unmatchedBook,
        ];
    }
}
