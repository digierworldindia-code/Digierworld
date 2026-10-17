<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessRuleException;
use App\Libraries\Dec;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Incentive policies and the incentive ledger.
 *
 * Sources: CRM conversions (contract value), finalised invoices of
 * converted clients (taxable or gross) and receipt allocations against
 * them (collections gross or net of tax). The basis is always the one the
 * policy names — never guessed. Every entry has a unique reference
 * (policy + source + employee), so re-running generation never creates a
 * duplicate and an entry can be included in only one payroll.
 * Cancelled invoices, credit notes and voided receipts reject open entries
 * or create clawbacks (with approval) for entries already paid.
 */
class IncentiveService
{
    public const BASES = [
        'contract_value' => 'Contract value at conversion', 'invoiced_taxable' => 'Invoiced value (taxable, excl. GST)',
        'invoiced_gross' => 'Invoiced value (incl. GST)', 'collections_net_of_tax' => 'Collections, net of GST', 'collections_gross' => 'Collections (gross)',
    ];

    private const REVENUE_DOCS = ['tax_invoice', 'bill_of_supply', 'cash_memo'];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    private function uid(): ?int
    {
        return service('authz')->currentUserId();
    }

    private function tx(callable $fn): mixed
    {
        $this->db->transBegin();
        try {
            $r = $fn();
            $this->db->transCommit();

            return $r;
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
    }

    // ---- policies -----------------------------------------------------------

    /** @param array<string, mixed> $in */
    public function savePolicy(array $in, ?int $id = null): int
    {
        service('authz')->authorize('hr.incentive.policy_manage');
        $name  = trim((string) ($in['name'] ?? ''));
        $basis = (string) ($in['basis'] ?? '');
        $value = trim((string) ($in['value'] ?? ''));
        $calc  = ($in['calc_type'] ?? '') === 'fixed' ? 'fixed' : 'percent';
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Name the policy.';
        }
        if (! isset(self::BASES[$basis])) {
            $errors['basis'] = 'Choose what the incentive is calculated on.';
        }
        if (! is_numeric($value) || (float) $value <= 0 || ($calc === 'percent' && (float) $value > 100)) {
            $errors['value'] = $calc === 'percent' ? 'Enter a percentage between 0 and 100.' : 'Enter the fixed amount.';
        }
        $scope = in_array($in['scope_type'] ?? '', ['all', 'role', 'user', 'service'], true) ? $in['scope_type'] : 'all';
        $ref   = trim((string) ($in['scope_ref'] ?? ''));
        if ($scope !== 'all' && $ref === '') {
            $errors['scope_ref'] = 'Choose who/what the policy applies to.';
        }
        if ($scope === 'service' && in_array($basis, ['contract_value', 'collections_net_of_tax', 'collections_gross'], true)) {
            $errors['scope_type'] = 'Service-specific policies work on invoiced values only.';
        }
        $from = ymd((string) ($in['effective_from'] ?? '')) ?? date('Y-m-01');
        $to   = ymd((string) ($in['effective_to'] ?? ''));
        if ($to !== null && $to < $from) {
            $errors['effective_to'] = 'End date is before the start date.';
        }
        if ($errors !== []) {
            throw new BusinessRuleException(reset($errors), $errors);
        }
        $row = [
            'name' => mb_substr($name, 0, 150), 'scope_type' => $scope, 'scope_ref' => $scope === 'all' ? null : mb_substr($ref, 0, 60), 'basis' => $basis,
            'calc_type' => $calc, 'value' => $value, 'min_threshold' => is_numeric($in['min_threshold'] ?? null) ? Dec::round($in['min_threshold']) : '0.00',
            'cutoff_day' => max(1, min(31, (int) ($in['cutoff_day'] ?? 31))), 'requires_approval' => empty($in['requires_approval']) ? 0 : 1,
            'clawback_on_refund' => empty($in['clawback_on_refund']) ? 0 : 1, 'carry_forward' => empty($in['carry_forward']) ? 0 : 1,
            'effective_from' => $from, 'effective_to' => $to, 'is_active' => empty($in['is_active']) ? 0 : 1,
        ];
        if ($id === null) {
            $this->db->table('incentive_policies')->insert($row + ['created_by' => $this->uid()]);
            $id = (int) $this->db->insertID();
        } else {
            $this->db->table('incentive_policies')->where('id', $id)->update($row + ['updated_by' => $this->uid()]);
        }
        service('audit')->log('incentive.policy_saved', 'incentive_policy', $id, null, $row, 'Incentive policy saved: ' . $row['name']);

        return $id;
    }

    /** [start, end] dates whose activity belongs to $period, honouring the policy cut-off day. */
    public static function window(string $period, int $cutoff): array
    {
        $prev = date('Y-m', strtotime($period . '-01 -1 month'));
        $pc   = min($cutoff, (int) date('t', strtotime($prev . '-01')));
        $tc   = min($cutoff, (int) date('t', strtotime($period . '-01')));

        return [date('Y-m-d', strtotime($prev . '-' . sprintf('%02d', $pc) . ' +1 day')), $period . '-' . sprintf('%02d', $tc)];
    }

    /** @return array<int, array<string, mixed>> employee rows keyed by user id, for incentive-eligible staff in the policy scope */
    private function eligible(array $policy): array
    {
        $b = $this->db->table('employees e')->select('e.*')->where('e.incentive_eligible', 1)->where('e.user_id IS NOT NULL', null, false);
        if ($policy['scope_type'] === 'user') {
            $b->where('e.user_id', (int) $policy['scope_ref']);
        } elseif ($policy['scope_type'] === 'role') {
            $b->join('user_roles ur', 'ur.user_id = e.user_id')->join('roles r', 'r.id = ur.role_id')->where('r.code', $policy['scope_ref'])
                ->groupStart()->where('ur.expires_at', null)->orWhere('ur.expires_at >', date('Y-m-d H:i:s'))->groupEnd();
        }

        return array_column($b->get()->getResultArray(), null, 'user_id');
    }

    /** @return list<array{user_id: int, share: float}> */
    private static function shares(array $link): array
    {
        $a = json_decode((string) ($link['attribution'] ?? ''), true);
        if (! is_array($a) || $a === []) {
            return [['user_id' => (int) $link['attributed_user_id'], 'share' => 100.0]];
        }

        return array_map(static fn ($r) => ['user_id' => (int) ($r['user_id'] ?? 0), 'share' => (float) ($r['share'] ?? 0)], $a);
    }

    /**
     * Propose incentive entries for a month. Idempotent: existing unique
     * references are skipped, so it can be re-run (cron or button) safely.
     *
     * @return array{created: int, skipped: int, below_threshold: int, reversed: int}
     */
    public function generate(string $period): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new BusinessRuleException('Choose a month.');
        }
        $out = ['created' => 0, 'skipped' => 0, 'below_threshold' => 0, 'reversed' => 0];
        $policies = $this->db->table('incentive_policies')->where('is_active', 1)->where('effective_from <=', date('Y-m-t', strtotime($period . '-01')))
            ->groupStart()->where('effective_to', null)->orWhere('effective_to >=', $period . '-01')->groupEnd()->get()->getResultArray();
        foreach ($policies as $p) {
            [$from, $to] = self::window($period, (int) $p['cutoff_day']);
            $from = max($from, $p['effective_from']);
            $to   = $p['effective_to'] !== null ? min($to, $p['effective_to']) : $to;
            $staff = $this->eligible($p);
            if ($staff === []) {
                continue;
            }
            $cands = [];
            foreach ($this->sourceRows($p, $from, $to) as $src) {
                foreach (self::shares($src) as $sh) {
                    $emp = $staff[$sh['user_id']] ?? null;
                    if ($emp === null || $sh['share'] <= 0) {
                        continue;
                    }
                    $basisAmt = Dec::mul($src['basis_amount'], (string) ($sh['share'] / 100), 2);
                    $amount   = $p['calc_type'] === 'percent' ? Dec::pct($basisAmt, $p['value']) : Dec::round(Dec::mul($p['value'], (string) ($sh['share'] / 100), 2));
                    $cands[(int) $emp['id']][] = $src + ['employee_id' => (int) $emp['id'], 'share' => $sh['share'], 'basis_share' => $basisAmt, 'amount' => $amount,
                        'unique_ref' => 'p' . $p['id'] . ':' . $src['ref'] . ':e' . $emp['id']];
                }
            }
            foreach ($cands as $empId => $items) {
                $total = Dec::add('0', ...array_column($items, 'basis_share'));
                if (Dec::cmp($total, $p['min_threshold']) < 0) {
                    $out['below_threshold'] += count($items);

                    continue;
                }
                foreach ($items as $it) {
                    if (! Dec::isPositive($it['amount'])) {
                        continue;
                    }
                    $this->db->query('INSERT IGNORE INTO incentive_entries (unique_ref, policy_id, employee_id, source_type, source_id, lead_id, contact_id, document_id, receipt_id, basis, basis_amount, share_percent, amount, period, status, approved_at, notes, created_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())', [
                        $it['unique_ref'], $p['id'], $empId, $it['source_type'], $it['source_id'], $it['lead_id'], $it['contact_id'], $it['document_id'], $it['receipt_id'],
                        $p['basis'], $it['basis_share'], number_format($it['share'], 2, '.', ''), $it['amount'], $period,
                        (int) $p['requires_approval'] === 1 ? 'proposed' : 'approved', (int) $p['requires_approval'] === 1 ? null : date('Y-m-d H:i:s'),
                        mb_substr($it['label'], 0, 500), $this->uid(),
                    ]);
                    $this->db->affectedRows() > 0 ? $out['created']++ : $out['skipped']++;
                }
            }
        }
        $out['reversed'] = $this->reverseCancelled();
        if ($out['created'] > 0) {
            service('audit')->log('incentive.generated', 'incentive_entry', null, null, $out + ['period' => $period], 'Incentive proposals generated for ' . $period);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> qualifying source rows with basis_amount, attribution and a stable ref */
    private function sourceRows(array $p, string $from, string $to): array
    {
        $rows = [];
        $link = "(SELECT lc.* FROM lead_conversion_links lc WHERE lc.contact_id = d.contact_id ORDER BY lc.id DESC LIMIT 1)";
        switch ($p['basis']) {
            case 'contract_value':
                foreach ($this->db->table('lead_conversion_links lc')->select('lc.*, l.company_name')->join('leads l', 'l.id = lc.lead_id')
                    ->where('lc.converted_at >=', $from . ' 00:00:00')->where('lc.converted_at <=', $to . ' 23:59:59')->where('lc.contract_value >', 0)->get()->getResultArray() as $c) {
                    $rows[] = ['ref' => 'conv' . $c['id'], 'source_type' => 'lead_conversion', 'source_id' => (int) $c['id'], 'lead_id' => (int) $c['lead_id'], 'contact_id' => (int) $c['contact_id'],
                        'document_id' => null, 'receipt_id' => null, 'basis_amount' => $c['contract_value'], 'attribution' => $c['attribution'], 'attributed_user_id' => $c['attributed_user_id'],
                        'label' => 'Conversion: ' . $c['company_name']];
                }
                break;
            case 'invoiced_taxable':
            case 'invoiced_gross':
                $docs = $this->db->query("SELECT d.id, d.document_number, d.contact_id, d.taxable_total, d.grand_total, d.bill_to_name, lc.id AS link_id, lc.lead_id, lc.attribution, lc.attributed_user_id
                    FROM billing_documents d JOIN lead_conversion_links lc ON lc.id = (SELECT MAX(x.id) FROM lead_conversion_links x WHERE x.contact_id = d.contact_id AND x.converted_at <= CONCAT(d.issue_date, ' 23:59:59'))
                    WHERE d.status = 'finalized' AND d.doc_type IN ('tax_invoice','bill_of_supply','cash_memo') AND d.issue_date BETWEEN ? AND ?", [$from, $to])->getResultArray();
                foreach ($docs as $d) {
                    $amt = $p['basis'] === 'invoiced_gross' ? $d['grand_total'] : $d['taxable_total'];
                    if ($p['scope_type'] === 'service') {
                        $amt = (string) ($this->db->query('SELECT COALESCE(SUM(' . ($p['basis'] === 'invoiced_gross' ? 'line_total' : 'taxable_value') . '), 0) s FROM billing_line_items WHERE document_id = ? AND service_id = ?', [$d['id'], (int) $p['scope_ref']])->getRow()->s ?? '0');
                    }
                    if (Dec::isPositive($amt)) {
                        $rows[] = ['ref' => 'inv' . $d['id'], 'source_type' => 'invoice', 'source_id' => (int) $d['id'], 'lead_id' => (int) $d['lead_id'], 'contact_id' => (int) $d['contact_id'],
                            'document_id' => (int) $d['id'], 'receipt_id' => null, 'basis_amount' => $amt, 'attribution' => $d['attribution'], 'attributed_user_id' => $d['attributed_user_id'],
                            'label' => 'Invoice ' . $d['document_number'] . ' — ' . $d['bill_to_name']];
                    }
                }
                break;
            default: // collections
                $allocs = $this->db->query("SELECT a.id, a.amount, a.receipt_id, a.document_id, r.receipt_number, d.document_number, d.contact_id, d.taxable_total, d.grand_total, lc.lead_id, lc.attribution, lc.attributed_user_id
                    FROM payment_allocations a JOIN payment_receipts r ON r.id = a.receipt_id AND r.status = 'active' AND r.receipt_type = 'receipt' AND r.cheque_status <> 'bounced'
                    JOIN billing_documents d ON d.id = a.document_id AND d.status = 'finalized'
                    JOIN lead_conversion_links lc ON lc.id = (SELECT MAX(x.id) FROM lead_conversion_links x WHERE x.contact_id = d.contact_id AND x.converted_at <= CONCAT(d.issue_date, ' 23:59:59'))
                    WHERE a.reversed_at IS NULL AND r.receipt_date BETWEEN ? AND ?", [$from, $to])->getResultArray();
                foreach ($allocs as $a) {
                    $amt = $p['basis'] === 'collections_gross' || ! Dec::isPositive($a['grand_total']) ? $a['amount'] : Dec::div(Dec::mul($a['amount'], $a['taxable_total'], 4), $a['grand_total'], 2);
                    $rows[] = ['ref' => 'alloc' . $a['id'], 'source_type' => 'receipt_allocation', 'source_id' => (int) $a['id'], 'lead_id' => (int) $a['lead_id'], 'contact_id' => (int) $a['contact_id'],
                        'document_id' => (int) $a['document_id'], 'receipt_id' => (int) $a['receipt_id'], 'basis_amount' => $amt, 'attribution' => $a['attribution'], 'attributed_user_id' => $a['attributed_user_id'],
                        'label' => 'Receipt ' . $a['receipt_number'] . ' against ' . $a['document_number']];
                }
        }

        return $rows;
    }

    /**
     * Cancelled invoices, voided/bounced receipts and reversed allocations:
     * open entries are rejected; entries already in payroll get a clawback
     * proposal (if the policy says so). Credit notes claw back pro rata.
     */
    public function reverseCancelled(): int
    {
        $n    = 0;
        $rows = $this->db->query("SELECT ie.*, p.clawback_on_refund FROM incentive_entries ie JOIN incentive_policies p ON p.id = ie.policy_id
            LEFT JOIN billing_documents d ON d.id = ie.document_id
            LEFT JOIN payment_receipts r ON r.id = ie.receipt_id
            LEFT JOIN payment_allocations a ON a.id = ie.source_id AND ie.source_type = 'receipt_allocation'
            WHERE ie.source_type IN ('invoice','receipt_allocation') AND ie.status IN ('proposed','approved','included','paid')
              AND (d.status = 'cancelled' OR r.status = 'void' OR r.cheque_status = 'bounced' OR a.reversed_at IS NOT NULL)")->getResultArray();
        foreach ($rows as $e) {
            $n += $this->reverseEntry($e, '1', 'Source cancelled / receipt voided');
        }
        // Credit notes against invoices that earned an invoice-based incentive
        $cns = $this->db->query("SELECT ie.*, p.clawback_on_refund, d.taxable_total, d.grand_total, cn.id AS cn_id, cn.document_number AS cn_no, cn.taxable_total AS cn_taxable, cn.grand_total AS cn_gross
            FROM incentive_entries ie JOIN incentive_policies p ON p.id = ie.policy_id JOIN billing_documents d ON d.id = ie.document_id
            JOIN billing_documents cn ON cn.original_document_id = d.id AND cn.doc_type = 'credit_note' AND cn.status = 'finalized'
            WHERE ie.source_type = 'invoice' AND ie.status IN ('proposed','approved','included','paid')")->getResultArray();
        foreach ($cns as $e) {
            $base = $e['basis'] === 'invoiced_gross' ? [$e['cn_gross'], $e['grand_total']] : [$e['cn_taxable'], $e['taxable_total']];
            $frac = Dec::isPositive($base[1]) ? Dec::div($base[0], $base[1], 6) : '0';
            $n   += $this->reverseEntry($e, Dec::min($frac, '1'), 'Credit note ' . $e['cn_no'], 'cn' . $e['cn_id']);
        }

        return $n;
    }

    private function reverseEntry(array $e, string $fraction, string $why, string $tag = 'void'): int
    {
        if (in_array($e['status'], ['proposed', 'approved'], true) && Dec::cmp($fraction, '1') >= 0) {
            $this->db->table('incentive_entries')->where('id', $e['id'])->whereIn('status', ['proposed', 'approved'])
                ->update(['status' => 'rejected', 'rejection_reason' => $why, 'updated_by' => $this->uid()]);

            return $this->db->affectedRows();
        }
        if ((int) $e['clawback_on_refund'] !== 1) {
            return 0;
        }
        $amount = Dec::neg(Dec::round(Dec::mul($e['amount'], $fraction, 4)));
        if (Dec::isZero($amount)) {
            return 0;
        }
        $this->db->query("INSERT IGNORE INTO incentive_entries (unique_ref, policy_id, employee_id, source_type, source_id, lead_id, contact_id, document_id, receipt_id, basis, basis_amount, share_percent, amount, period, status, clawback_of_id, notes, created_by, created_at)
            VALUES (?, ?, ?, 'clawback', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'proposed', ?, ?, ?, NOW())", [
            'claw:' . $e['id'] . ':' . $tag, $e['policy_id'], $e['employee_id'], $e['source_id'], $e['lead_id'], $e['contact_id'], $e['document_id'], $e['receipt_id'],
            $e['basis'], Dec::neg(Dec::round(Dec::mul($e['basis_amount'], $fraction, 4))), $e['share_percent'], $amount, date('Y-m'), $e['id'], mb_substr('Clawback: ' . $why, 0, 500), $this->uid(),
        ]);

        return $this->db->affectedRows();
    }

    /** @param array<string, mixed> $in */
    public function addManual(array $in): int
    {
        service('authz')->authorize('hr.incentive.approve');
        $emp    = (int) ($in['employee_id'] ?? 0);
        $amount = str_replace(',', '', trim((string) ($in['amount'] ?? '')));
        $policy = (int) ($in['policy_id'] ?? 0);
        $reason = trim((string) ($in['notes'] ?? ''));
        $period = (string) ($in['period'] ?? date('Y-m'));
        if (! $emp || ! is_numeric($amount) || Dec::isZero($amount) || ! $policy || mb_strlen($reason) < 5 || ! preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new BusinessRuleException('Choose the employee, policy and month, enter the amount and explain the entry.');
        }
        $this->db->table('incentive_entries')->insert([
            'unique_ref' => 'manual:' . bin2hex(random_bytes(8)), 'policy_id' => $policy, 'employee_id' => $emp, 'source_type' => 'manual', 'basis' => 'manual',
            'basis_amount' => Dec::round($amount), 'share_percent' => '100.00', 'amount' => Dec::round($amount), 'period' => $period, 'status' => 'proposed',
            'notes' => mb_substr($reason, 0, 500), 'created_by' => $this->uid(),
        ]);
        $id = (int) $this->db->insertID();
        service('audit')->log('incentive.manual', 'incentive_entry', $id, null, ['employee_id' => $emp, 'amount' => $amount], 'Manual incentive entry');

        return $id;
    }

    public function decide(int $id, string $decision, string $reason = ''): void
    {
        service('authz')->authorize('hr.incentive.approve');
        $e = $this->db->table('incentive_entries')->where('id', $id)->get()->getRowArray() ?? throw new BusinessRuleException('Entry not found.');
        if ($e['status'] !== 'proposed' && ! ($decision === 'rejected' && $e['status'] === 'approved')) {
            throw new BusinessRuleException('Only proposed entries can be approved; included or paid entries are locked.');
        }
        $empUser = $this->db->table('employees')->select('user_id')->where('id', $e['employee_id'])->get()->getRow()->user_id ?? null;
        if ($empUser !== null && (int) $empUser === (int) $this->uid() && ! service('authz')->isSuper()) {
            throw new BusinessRuleException('You cannot approve your own incentive.');
        }
        if ($decision === 'rejected' && mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleException('Give the reason for rejecting.');
        }
        $this->db->table('incentive_entries')->where('id', $id)->update($decision === 'approved'
            ? ['status' => 'approved', 'approved_by' => $this->uid(), 'approved_at' => date('Y-m-d H:i:s'), 'updated_by' => $this->uid()]
            : ['status' => 'rejected', 'rejection_reason' => mb_substr(trim($reason), 0, 500), 'updated_by' => $this->uid()]);
        service('audit')->log('incentive.' . $decision, 'incentive_entry', $id, ['status' => $e['status']], ['status' => $decision, 'reason' => $reason], 'Incentive ' . $decision);
    }

    /** Split credit: move a share of a proposed/approved entry to another employee. */
    public function split(int $id, int $toEmployeeId, float $percent, string $reason): void
    {
        service('authz')->authorize('hr.incentive.approve');
        $e = $this->db->table('incentive_entries')->where('id', $id)->get()->getRowArray() ?? throw new BusinessRuleException('Entry not found.');
        if (! in_array($e['status'], ['proposed', 'approved'], true) || $e['source_type'] === 'clawback') {
            throw new BusinessRuleException('Only open entries can be split.');
        }
        if ($percent <= 0 || $percent >= 100 || $toEmployeeId === (int) $e['employee_id'] || mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleException('Choose another employee, a share between 0 and 100% and give a reason.');
        }
        $this->tx(function () use ($e, $toEmployeeId, $percent, $reason): void {
            $frac     = (string) ($percent / 100);
            $moveAmt  = Dec::round(Dec::mul($e['amount'], $frac, 4));
            $moveBase = Dec::round(Dec::mul($e['basis_amount'], $frac, 4));
            $this->db->table('incentive_entries')->insert([
                'unique_ref' => mb_substr($e['unique_ref'] . ':split:e' . $toEmployeeId, 0, 160), 'policy_id' => $e['policy_id'], 'employee_id' => $toEmployeeId,
                'source_type' => $e['source_type'], 'source_id' => $e['source_id'], 'lead_id' => $e['lead_id'], 'contact_id' => $e['contact_id'], 'document_id' => $e['document_id'],
                'receipt_id' => $e['receipt_id'], 'basis' => $e['basis'], 'basis_amount' => $moveBase, 'share_percent' => number_format((float) $e['share_percent'] * $percent / 100, 2, '.', ''),
                'amount' => $moveAmt, 'period' => $e['period'], 'status' => 'proposed', 'notes' => mb_substr('Split from #' . $e['id'] . ': ' . $reason, 0, 500), 'created_by' => $this->uid(),
            ]);
            $this->db->table('incentive_entries')->where('id', $e['id'])->update([
                'amount' => Dec::sub($e['amount'], $moveAmt), 'basis_amount' => Dec::sub($e['basis_amount'], $moveBase),
                'share_percent' => number_format((float) $e['share_percent'] * (100 - $percent) / 100, 2, '.', ''), 'updated_by' => $this->uid(),
            ]);
            service('audit')->log('incentive.split', 'incentive_entry', (int) $e['id'], null, ['to_employee_id' => $toEmployeeId, 'percent' => $percent, 'reason' => $reason], 'Incentive credit split');
        });
    }
}
