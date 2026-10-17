<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRuleException;
use App\Libraries\Dec;
use App\Services\Billing\DocumentNumberAllocator;
use CodeIgniter\Database\BaseConnection;

/**
 * Double-entry journal. Every money movement is one balanced entry
 * (Σ debit = Σ credit, enforced here AND by a DB CHECK constraint).
 * Entries are never edited or deleted; corrections are reversal entries.
 */
class LedgerService
{
    /** @var array<string, int> */
    private array $accountCache = [];

    public function __construct(private readonly BaseConnection $db, private readonly DocumentNumberAllocator $numbers)
    {
    }

    /** Ledger id for a system sub_type (e.g. 'receivable', 'output_cgst'). */
    public function account(string $subType): int
    {
        if (! isset($this->accountCache[$subType])) {
            $row = $this->db->table('ledger_accounts')->select('id')->where(['sub_type' => $subType, 'is_system' => 1])->orderBy('id')->get()->getRowArray()
                ?? $this->db->table('ledger_accounts')->select('id')->where('sub_type', $subType)->orderBy('id')->get()->getRowArray();
            if ($row === null) {
                throw new BusinessRuleException("Ledger account for '{$subType}' is missing — run php spark db:seed MasterDataSeeder.");
            }
            $this->accountCache[$subType] = (int) $row['id'];
        }

        return $this->accountCache[$subType];
    }

    public function bankLedger(int $bankCashAccountId): int
    {
        $row = $this->db->table('bank_cash_accounts')->select('ledger_account_id')->where('id', $bankCashAccountId)->get()->getRowArray();
        if ($row === null) {
            throw new BusinessRuleException('Unknown bank/cash account.');
        }

        return (int) $row['ledger_account_id'];
    }

    /**
     * Post a balanced entry. Must be called inside the caller's transaction.
     *
     * @param list<array{account: int, debit?: string, credit?: string, contact_id?: int|null, employee_id?: int|null, bank_cash_account_id?: int|null, memo?: string|null}> $lines
     */
    public function post(string $date, string $sourceType, ?int $sourceId, string $narration, array $lines, ?string $reference = null, ?int $reversalOf = null): int
    {
        $debit  = '0';
        $credit = '0';
        $clean  = [];
        foreach ($lines as $l) {
            // Net the line to a single non-negative side (e.g. a negative round-off
            // credit becomes a debit).
            $net = Dec::sub($l['debit'] ?? '0', $l['credit'] ?? '0');
            if (Dec::isZero($net)) {
                continue;
            }
            [$d, $c] = Dec::isPositive($net) ? [Dec::round($net), '0.00'] : ['0.00', Dec::abs($net)];
            $debit  = Dec::add($debit, $d);
            $credit = Dec::add($credit, $c);
            $clean[] = [
                'ledger_account_id'    => $l['account'],
                'contact_id'           => $l['contact_id'] ?? null,
                'employee_id'          => $l['employee_id'] ?? null,
                'bank_cash_account_id' => $l['bank_cash_account_id'] ?? null,
                'debit'                => $d,
                'credit'               => $c,
                'memo'                 => isset($l['memo']) ? mb_substr((string) $l['memo'], 0, 255) : null,
            ];
        }
        if ($clean === []) {
            throw new BusinessRuleException('Journal entry has no amounts.');
        }
        if (Dec::cmp($debit, $credit) !== 0) {
            throw new BusinessRuleException("Journal entry is not balanced (Dr {$debit} ≠ Cr {$credit}).");
        }
        $fy = service('fy')->forDate($date);
        $no = $this->numbers->allocate('journal', $date)['formatted'];
        $this->db->table('journal_entries')->insert([
            'entry_no'          => $no,
            'entry_date'        => $date,
            'period'            => substr($date, 0, 7),
            'financial_year_id' => $fy['id'],
            'source_type'       => $sourceType,
            'source_id'         => $sourceId,
            'narration'         => mb_substr($narration, 0, 255),
            'reference'         => $reference,
            'total_debit'       => $debit,
            'total_credit'      => $credit,
            'reversal_of_id'    => $reversalOf,
            'posted_by'         => service('authz')->currentUserId(),
        ]);
        $id = (int) $this->db->insertID();
        foreach ($clean as $line) {
            $this->db->table('journal_lines')->insert($line + ['journal_entry_id' => $id]);
        }

        return $id;
    }

    /** Post the mirror image of an entry and link both ways. */
    public function reverse(int $entryId, string $date, string $reason): int
    {
        $entry = $this->db->query('SELECT * FROM journal_entries WHERE id = ? FOR UPDATE', [$entryId])->getRowArray()
            ?? throw new BusinessRuleException('Journal entry not found.');
        if ($entry['reversed_by_id'] !== null) {
            throw new BusinessRuleException('This entry has already been reversed.');
        }
        $lines = $this->db->table('journal_lines')->where('journal_entry_id', $entryId)->get()->getResultArray();
        $mirror = array_map(static fn ($l) => [
            'account' => (int) $l['ledger_account_id'], 'debit' => $l['credit'], 'credit' => $l['debit'],
            'contact_id' => $l['contact_id'], 'employee_id' => $l['employee_id'], 'bank_cash_account_id' => $l['bank_cash_account_id'],
            'memo' => 'Reversal: ' . $l['memo'],
        ], $lines);
        $rev = $this->post($date, $entry['source_type'], $entry['source_id'] === null ? null : (int) $entry['source_id'], 'Reversal of ' . $entry['entry_no'] . ': ' . $reason, $mirror, $entry['reference'], $entryId);
        $this->db->table('journal_entries')->where('id', $entryId)->update(['reversed_by_id' => $rev]);

        return $rev;
    }

    /** Balance (Dr − Cr) of a ledger account, optionally per contact, up to a date. */
    public function balance(int $accountId, ?string $upTo = null, ?int $contactId = null): string
    {
        $b = $this->db->table('journal_lines jl')->select('COALESCE(SUM(jl.debit - jl.credit),0) bal', false)
            ->join('journal_entries je', 'je.id = jl.journal_entry_id')->where('jl.ledger_account_id', $accountId);
        if ($upTo !== null) {
            $b->where('je.entry_date <=', $upTo);
        }
        if ($contactId !== null) {
            $b->where('jl.contact_id', $contactId);
        }

        return Dec::round($b->get()->getRow()->bal ?? '0');
    }

    /** Book balance of a bank/cash account (opening balance is a journal entry too). */
    public function bankBalance(int $bankCashAccountId, ?string $upTo = null): string
    {
        return $this->balance($this->bankLedger($bankCashAccountId), $upTo);
    }

    /**
     * Keep the opening-balance journal of a bank/cash account in step with
     * its master record: Dr bank / Cr Opening Balance Equity (or the reverse
     * for an overdraft). Changing it reverses the previous opening entry.
     * Must be called inside the caller's transaction.
     */
    public function syncOpeningBalance(int $bankCashAccountId): void
    {
        $acc     = $this->db->table('bank_cash_accounts')->where('id', $bankCashAccountId)->get()->getRowArray() ?? throw new BusinessRuleException('Unknown bank/cash account.');
        $amount  = Dec::round($acc['opening_balance'] ?? '0');
        $date    = $acc['opening_date'] ?: date('Y-m-d');
        $current = $this->db->table('journal_entries')->where(['source_type' => 'opening_balance', 'source_id' => $bankCashAccountId, 'reversed_by_id' => null, 'reversal_of_id' => null])->get()->getRowArray();
        if ($current !== null) {
            $posted = $this->db->table('journal_lines')->select('COALESCE(SUM(debit - credit),0) n', false)->where(['journal_entry_id' => $current['id'], 'ledger_account_id' => $acc['ledger_account_id']])->get()->getRow()->n;
            if (Dec::cmp($posted, $amount) === 0 && $current['entry_date'] === $date) {
                return;
            }
            $this->reverse((int) $current['id'], $date, 'Opening balance changed');
        }
        if (Dec::isZero($amount)) {
            return;
        }
        $this->post($date, 'opening_balance', $bankCashAccountId, 'Opening balance — ' . $acc['display_name'], [
            ['account' => (int) $acc['ledger_account_id'], 'debit' => $amount, 'bank_cash_account_id' => $bankCashAccountId],
            ['account' => $this->account('opening_equity'), 'credit' => $amount],
        ]);
    }
}
