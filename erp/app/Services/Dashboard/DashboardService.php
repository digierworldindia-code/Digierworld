<?php

namespace App\Services\Dashboard;

use App\Libraries\Dec;
use App\Services\Security\Authorization;
use CodeIgniter\Database\BaseConnection;

/**
 * Dashboard KPIs. Definitions (also shown as tooltips):
 *  - Invoiced: Σ grand total of FINALIZED tax invoices + bills of supply (issue date in period).
 *  - Cash memos: Σ grand total of finalized cash memos (separate series, same tax rules).
 *  - Taxable sales value: Σ line taxable value of finalized outward supplies with a taxable
 *    treatment, minus finalized credit notes.
 *  - Collected: Σ customer receipts − refunds (receipt date in period). Transfers excluded.
 *  - Outstanding: Σ balance due of finalized sale documents as of today.
 *  - GST liability (estimate): output tax − credit-note tax − eligible ITC (approved expenses).
 *  - Net cash movement: Δ of cash + bank ledgers in period (internal transfers net to zero).
 * Receipts never add to sales; deposits never add to collections.
 */
class DashboardService
{
    private const SALE_TYPES = ['tax_invoice', 'bill_of_supply', 'cash_memo', 'debit_note'];

    public function __construct(private readonly BaseConnection $db, private readonly Authorization $authz)
    {
    }

    /** @return array{0: string, 1: string, 2: string} */
    public function resolvePeriod(string $period, mixed $from = null, mixed $to = null): array
    {
        $today = date('Y-m-d');
        $fy    = fy_for_date($today);

        return match ($period) {
            'last_month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month')), 'Last month'],
            'this_fy'    => [$fy['start_date'], $fy['end_date'], 'FY ' . $fy['code']],
            'last_fy'    => (static function () use ($fy) {
                $prev = fy_for_date(date('Y-m-d', strtotime($fy['start_date'] . ' -1 day')));

                return [$prev['start_date'], $prev['end_date'], 'FY ' . $prev['code']];
            })(),
            'custom'     => (static function () use ($from, $to, $today) {
                $f = ymd((string) $from) ?? date('Y-m-01');
                $t = ymd((string) $to) ?? $today;
                if ($t < $f) {
                    [$f, $t] = [$t, $f];
                }

                return [$f, $t, dmy($f) . ' to ' . dmy($t)];
            })(),
            default      => [date('Y-m-01'), date('Y-m-t'), 'This month'],
        };
    }

    /** @return list<array{label: string, done: bool, url: string}> */
    public function setupChecklist(): array
    {
        if (! $this->authz->can('settings.company.edit')) {
            return [];
        }
        $c = $this->db->table('company_settings')->where('id', 1)->get()->getRowArray() ?? [];

        return [
            ['label' => 'Legal business identity (name, GSTIN, PAN, address, state)', 'done' => ! empty($c['legal_name']) && ! empty($c['state_code']), 'url' => 'settings/company'],
            ['label' => 'Upload official logo (kept unchanged)', 'done' => $this->db->table('branding_assets')->where(['asset_type' => 'logo', 'is_active' => 1])->countAllResults() > 0, 'url' => 'settings/branding'],
            ['label' => 'Bank / UPI payment profile', 'done' => $this->db->table('bank_cash_accounts')->where('account_kind', 'bank')->countAllResults() > 0, 'url' => 'settings/banks'],
            ['label' => 'Document series & last-used invoice number', 'done' => ! empty(($c['setup_steps'] ?? null) ? (json_decode($c['setup_steps'], true)['sequences'] ?? false) : false), 'url' => 'settings/sequences'],
            ['label' => 'Accountant review of tax rates & service SAC codes', 'done' => $this->db->table('tax_rates')->where('review_status', 'accountant_reviewed')->countAllResults() > 0, 'url' => 'settings/tax'],
            ['label' => 'Signature/stamp policy', 'done' => $this->db->table('signature_policies')->countAllResults() > 0, 'url' => 'settings/signature-policy'],
            ['label' => 'Invite team users and assign roles', 'done' => $this->db->table('users')->countAllResults() > 1, 'url' => 'admin/users'],
        ];
    }

    /** @return array<string, mixed> */
    public function widgets(string $from, string $to): array
    {
        $w   = [];
        $uid = (int) $this->authz->currentUserId();

        if ($this->authz->can('dashboard.finance_kpis')) {
            $w['finance'] = $this->financeKpis($from, $to);
        }
        if ($this->authz->can('dashboard.payroll_kpis')) {
            $w['payroll'] = $this->payrollKpis($from, $to);
        }
        $leadScope = service('leadAccess')->scope();
        if ($leadScope['mode'] !== 'none') {
            $w['crm'] = $this->crmKpis($from, $to, $leadScope, $this->authz->can('dashboard.crm_kpis'));
        }
        if ($this->authz->can('dashboard.hr_kpis')) {
            $w['hr'] = $this->hrKpis();
        }
        if ($this->authz->canAny(['crm.sheets.view', 'crm.sheets.manage'])) {
            $w['sync'] = $this->syncStatus();
        }
        $types = service('billingAccess')->visibleTypes();
        if ($types !== [] && ! $this->authz->can('dashboard.finance_kpis')) {
            $w['my_billing'] = [
                'drafts'    => (int) $this->db->table('billing_documents')->where(['created_by' => $uid, 'status' => 'draft'])->countAllResults(),
                'finalized' => (int) $this->db->table('billing_documents')->where(['created_by' => $uid, 'status' => 'finalized'])
                    ->where('issue_date >=', $from)->where('issue_date <=', $to)->countAllResults(),
            ];
        }
        if ($this->authz->canAny(['crm.task.view_own', 'crm.task.view_all'])) {
            $w['tasks'] = $this->db->table('tasks')->where('assigned_to', $uid)->whereIn('status', ['pending', 'in_progress'])
                ->orderBy('due_at IS NULL', 'ASC', false)->orderBy('due_at')->limit(6)->get()->getResultArray();
        }

        return $w;
    }

    /** @return array<string, string> */
    public function financeKpis(string $from, string $to): array
    {
        $sum = function (string $sql, array $binds): string {
            $row = $this->db->query($sql, $binds)->getRowArray();

            return Dec::round($row['v'] ?? '0');
        };
        $period = [$from, $to];
        $today  = date('Y-m-d');

        $invoiced = $sum("SELECT COALESCE(SUM(grand_total),0) v FROM billing_documents WHERE status='finalized' AND doc_type IN ('tax_invoice','bill_of_supply') AND issue_date BETWEEN ? AND ?", $period);
        $memos    = $sum("SELECT COALESCE(SUM(grand_total),0) v FROM billing_documents WHERE status='finalized' AND doc_type='cash_memo' AND issue_date BETWEEN ? AND ?", $period);
        $credit   = $sum("SELECT COALESCE(SUM(grand_total),0) v FROM billing_documents WHERE status='finalized' AND doc_type='credit_note' AND issue_date BETWEEN ? AND ?", $period);
        $taxable  = $sum(
            "SELECT COALESCE(SUM(CASE WHEN d.doc_type='credit_note' THEN -li.taxable_value ELSE li.taxable_value END),0) v
             FROM billing_line_items li JOIN billing_documents d ON d.id = li.document_id
             WHERE d.status='finalized' AND d.doc_type IN ('tax_invoice','cash_memo','debit_note','credit_note')
               AND li.tax_treatment NOT IN ('exempt','nil_rated','non_gst') AND d.issue_date BETWEEN ? AND ?",
            $period
        );
        $taxRow = $this->db->query(
            "SELECT COALESCE(SUM(CASE WHEN doc_type='credit_note' THEN -cgst_total ELSE cgst_total END),0) cgst,
                    COALESCE(SUM(CASE WHEN doc_type='credit_note' THEN -sgst_total ELSE sgst_total END),0) sgst,
                    COALESCE(SUM(CASE WHEN doc_type='credit_note' THEN -utgst_total ELSE utgst_total END),0) utgst,
                    COALESCE(SUM(CASE WHEN doc_type='credit_note' THEN -igst_total ELSE igst_total END),0) igst,
                    COALESCE(SUM(CASE WHEN doc_type='credit_note' THEN -cess_total ELSE cess_total END),0) cess
             FROM billing_documents WHERE status='finalized' AND reverse_charge = 0
               AND doc_type IN ('tax_invoice','cash_memo','debit_note','credit_note') AND issue_date BETWEEN ? AND ?",
            $period
        )->getRowArray();
        $output = Dec::add($taxRow['cgst'], $taxRow['sgst'], $taxRow['utgst'], $taxRow['igst'], $taxRow['cess']);
        $itc    = $sum("SELECT COALESCE(SUM(itc_eligible_amount),0) v FROM expenses WHERE approval_status='approved' AND deleted_at IS NULL AND expense_date BETWEEN ? AND ?", $period);

        $receipts = $sum("SELECT COALESCE(SUM(amount),0) v FROM payment_receipts WHERE status='active' AND receipt_type='receipt' AND receipt_date BETWEEN ? AND ?", $period);
        $refunds  = $sum("SELECT COALESCE(SUM(amount),0) v FROM payment_receipts WHERE status='active' AND receipt_type='refund' AND receipt_date BETWEEN ? AND ?", $period);
        $expenses = $sum("SELECT COALESCE(SUM(taxable_amount + itc_ineligible_amount),0) v FROM expenses WHERE approval_status='approved' AND deleted_at IS NULL AND expense_date BETWEEN ? AND ?", $period);

        $outstanding = $sum("SELECT COALESCE(SUM(balance_due),0) v FROM billing_documents WHERE status='finalized' AND doc_type IN ('tax_invoice','bill_of_supply','cash_memo','debit_note') AND balance_due > 0", []);
        $overdue     = $sum("SELECT COALESCE(SUM(balance_due),0) v FROM billing_documents WHERE status='finalized' AND doc_type IN ('tax_invoice','bill_of_supply','cash_memo','debit_note') AND balance_due > 0 AND due_date < ?", [$today]);
        $advances    = $sum('SELECT COALESCE(SUM(unapplied_amount),0) v FROM payment_receipts WHERE status=\'active\' AND receipt_type=\'receipt\'', []);

        $cashMove = $sum(
            "SELECT COALESCE(SUM(jl.debit - jl.credit),0) v FROM journal_lines jl
             JOIN journal_entries je ON je.id = jl.journal_entry_id
             JOIN bank_cash_accounts b ON b.ledger_account_id = jl.ledger_account_id
             WHERE je.entry_date BETWEEN ? AND ?",
            $period
        );
        $balances = $this->db->query(
            "SELECT b.account_kind, b.ownership, COALESCE(SUM(b.opening_balance),0) + COALESCE((SELECT SUM(jl.debit - jl.credit) FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id WHERE jl.ledger_account_id = b.ledger_account_id AND je.entry_date <= ?),0) bal
             FROM bank_cash_accounts b WHERE b.is_active = 1 GROUP BY b.id",
            [$to]
        )->getResultArray();
        $cash = '0';
        $bank = '0';
        foreach ($balances as $b) {
            if ($b['account_kind'] === 'cash') {
                $cash = Dec::add($cash, $b['bal']);
            } else {
                $bank = Dec::add($bank, $b['bal']);
            }
        }
        $deposited = $sum(
            "SELECT COALESCE(SUM(t.amount),0) v FROM money_transfers t JOIN bank_cash_accounts f ON f.id = t.from_account_id
             WHERE t.status='active' AND f.account_kind='cash' AND t.transfer_date BETWEEN ? AND ?",
            $period
        );
        $payroll = $sum("SELECT COALESCE(SUM(total_gross),0) v FROM payroll_runs WHERE status IN ('finalized','partially_paid','paid') AND period_start BETWEEN ? AND ?", $period);

        return [
            'invoiced'          => $invoiced,
            'cash_memos'        => $memos,
            'credit_notes'      => $credit,
            'taxable_sales'     => $taxable,
            'collected'         => Dec::sub($receipts, $refunds),
            'receipts'          => $receipts,
            'refunds'           => $refunds,
            'expenses'          => $expenses,
            'outstanding'       => $outstanding,
            'overdue'           => $overdue,
            'advances'          => $advances,
            'cgst'              => Dec::round($taxRow['cgst']),
            'sgst'              => Dec::round($taxRow['sgst']),
            'utgst'             => Dec::round($taxRow['utgst']),
            'igst'              => Dec::round($taxRow['igst']),
            'output_tax'        => $output,
            'itc'               => $itc,
            'gst_liability'     => Dec::sub($output, $itc),
            'net_cash_movement' => $cashMove,
            'cash_balance'      => $cash,
            'bank_balance'      => $bank,
            'cash_deposited'    => $deposited,
            'payroll_gross'     => $this->authz->can('dashboard.payroll_kpis') ? $payroll : '',
        ];
    }

    /** @return array<string, string> */
    private function payrollKpis(string $from, string $to): array
    {
        $row = $this->db->query(
            "SELECT COALESCE(SUM(total_gross),0) gross, COALESCE(SUM(total_net),0) net, COALESCE(SUM(total_net - total_paid),0) unpaid
             FROM payroll_runs WHERE status IN ('finalized','partially_paid','paid') AND period_start BETWEEN ? AND ?",
            [$from, $to]
        )->getRowArray();
        $unpaidAll = $this->db->query("SELECT COALESCE(SUM(balance),0) v FROM payroll_items pi JOIN payroll_runs pr ON pr.id = pi.payroll_run_id WHERE pr.is_locked = 1 AND pi.balance > 0")->getRowArray();

        return ['gross' => Dec::round($row['gross']), 'net' => Dec::round($row['net']), 'unpaid_period' => Dec::round($row['unpaid']), 'unpaid_all' => Dec::round($unpaidAll['v'] ?? '0')];
    }

    /**
     * @param array{mode: string, user_ids: list<int>} $scope
     *
     * @return array<string, mixed>
     */
    private function crmKpis(string $from, string $to, array $scope, bool $teamView): array
    {
        $uid   = (int) $this->authz->currentUserId();
        $users = $scope['mode'] === 'all' ? null : ($teamView ? $scope['user_ids'] : [$uid]);
        if (! $teamView) {
            $users = [$uid];
        }
        $in    = static fn (string $col) => $users === null ? '1=1' : ($col . ' IN (' . implode(',', array_map('intval', $users ?: [0])) . ')');
        $today = date('Y-m-d');
        $now   = date('Y-m-d H:i:s');
        $q     = fn (string $sql, array $b = []) => (int) ($this->db->query($sql, $b)->getRowArray()['v'] ?? 0);

        return [
            'scope_label'       => $users === null ? 'All teams' : (count($users) > 1 ? 'My team' : 'Mine'),
            'calls_today'       => $q('SELECT COUNT(*) v FROM call_logs WHERE ' . $in('caller_id') . ' AND DATE(called_at) = ?', [$today]),
            'connected_today'   => $q('SELECT COUNT(*) v FROM call_logs WHERE is_connected = 1 AND ' . $in('caller_id') . ' AND DATE(called_at) = ?', [$today]),
            'due_today'         => $q("SELECT COUNT(*) v FROM followups WHERE status='pending' AND " . $in('owner_id') . ' AND DATE(due_at) = ?', [$today]),
            'overdue'           => $q("SELECT COUNT(*) v FROM followups WHERE status='pending' AND " . $in('owner_id') . ' AND due_at < ?', [$now]),
            'visits_today'      => $q("SELECT COUNT(*) v FROM visits WHERE status='scheduled' AND " . $in('visitor_id') . ' AND DATE(scheduled_at) = ?', [$today]),
            'meetings_today'    => $q("SELECT COUNT(*) v FROM meetings WHERE status='scheduled' AND " . $in('organizer_id') . ' AND DATE(meeting_at) = ?', [$today]),
            'new_leads'         => $q('SELECT COUNT(*) v FROM leads WHERE deleted_at IS NULL AND ' . $in('assigned_to') . ' AND DATE(created_at) BETWEEN ? AND ?', [$from, $to]),
            'conversions'       => $q('SELECT COUNT(*) v FROM lead_conversion_links lc JOIN leads l ON l.id = lc.lead_id WHERE ' . $in('l.assigned_to') . ' AND DATE(lc.converted_at) BETWEEN ? AND ?', [$from, $to]),
            'calls_period'      => $q('SELECT COUNT(*) v FROM call_logs WHERE ' . $in('caller_id') . ' AND DATE(called_at) BETWEEN ? AND ?', [$from, $to]),
            'owner_visits_open' => $q("SELECT COUNT(*) v FROM visits WHERE status='scheduled' AND visitor_id = ?", [$uid]),
        ];
    }

    /** @return array<string, mixed> */
    private function hrKpis(): array
    {
        $today = date('Y-m-d');
        $att   = $this->db->query('SELECT status, COUNT(*) n FROM attendance WHERE att_date = ? GROUP BY status', [$today])->getResultArray();
        $pipe  = $this->db->query("SELECT status, COUNT(*) n FROM candidates WHERE deleted_at IS NULL AND status NOT IN ('rejected','joined','withdrawn') GROUP BY status")->getResultArray();

        return [
            'open_vacancies' => (int) $this->db->table('job_openings')->where('status', 'open')->countAllResults(),
            'pipeline'       => array_column($pipe, 'n', 'status'),
            'attendance'     => array_column($att, 'n', 'status'),
            'active_staff'   => (int) $this->db->table('employees')->whereIn('employment_status', ['active', 'probation', 'notice'])->countAllResults(),
            'pending_leaves' => (int) $this->db->table('leave_requests')->where('status', 'pending')->countAllResults(),
        ];
    }

    /** @return array<string, mixed> */
    private function syncStatus(): array
    {
        $s = $this->db->table('google_sync_settings')->where('id', 1)->get()->getRowArray() ?? [];

        return [
            'enabled'      => (bool) ($s['enabled'] ?? false),
            'configured'   => ! empty($s['spreadsheet_id']) && ! empty($s['credentials_path']) && is_file((string) $s['credentials_path']),
            'last_success' => $s['last_success_at'] ?? null,
            'last_error'   => $s['last_error'] ?? null,
            'pending'      => (int) $this->db->table('google_sync_jobs')->whereIn('status', ['pending', 'processing'])->countAllResults(),
            'failed'       => (int) $this->db->table('google_sync_jobs')->whereIn('status', ['failed', 'dead'])->countAllResults(),
        ];
    }

    /** Chart datasets, each gated by permission. @return array<string, mixed> */
    public function charts(string $from, string $to): array
    {
        $charts = [];
        if ($this->authz->can('dashboard.finance_kpis')) {
            $start = date('Y-m-01', strtotime($to . ' -11 months'));
            $billed = $this->db->query(
                "SELECT DATE_FORMAT(issue_date,'%Y-%m') m, SUM(CASE WHEN doc_type='credit_note' THEN -grand_total ELSE grand_total END) v
                 FROM billing_documents WHERE status='finalized' AND doc_type IN ('tax_invoice','bill_of_supply','cash_memo','debit_note','credit_note')
                 AND issue_date BETWEEN ? AND ? GROUP BY m",
                [$start, $to]
            )->getResultArray();
            $collected = $this->db->query(
                "SELECT DATE_FORMAT(receipt_date,'%Y-%m') m, SUM(CASE WHEN receipt_type='refund' THEN -amount ELSE amount END) v
                 FROM payment_receipts WHERE status='active' AND receipt_date BETWEEN ? AND ? GROUP BY m",
                [$start, $to]
            )->getResultArray();
            $months = [];
            for ($i = 0; $i < 12; $i++) {
                $months[] = date('Y-m', strtotime($start . " +{$i} months"));
            }
            $bMap = array_column($billed, 'v', 'm');
            $cMap = array_column($collected, 'v', 'm');
            $charts['billed_vs_collected'] = [
                'labels'    => array_map(static fn ($m) => date('M y', strtotime($m . '-01')), $months),
                'billed'    => array_map(static fn ($m) => (float) ($bMap[$m] ?? 0), $months),
                'collected' => array_map(static fn ($m) => (float) ($cMap[$m] ?? 0), $months),
            ];
            $k = $this->financeKpis($from, $to);
            $charts['gst_split'] = ['labels' => ['CGST', 'SGST', 'UTGST', 'IGST'], 'values' => [(float) $k['cgst'], (float) $k['sgst'], (float) $k['utgst'], (float) $k['igst']]];
            $modes = $this->db->query("SELECT mode, SUM(amount) v FROM payment_receipts WHERE status='active' AND receipt_type='receipt' AND receipt_date BETWEEN ? AND ? GROUP BY mode", [$from, $to])->getResultArray();
            $charts['payment_modes'] = ['labels' => array_map(static fn ($r) => strtoupper(str_replace('_', ' ', $r['mode'])), $modes), 'values' => array_map(static fn ($r) => (float) $r['v'], $modes)];
            $exp = $this->db->query(
                "SELECT ec.name, SUM(e.taxable_amount + e.itc_ineligible_amount) v FROM expenses e JOIN expense_categories ec ON ec.id = e.category_id
                 WHERE e.approval_status='approved' AND e.deleted_at IS NULL AND e.expense_date BETWEEN ? AND ? GROUP BY ec.id ORDER BY v DESC LIMIT 8",
                [$from, $to]
            )->getResultArray();
            $charts['expenses'] = ['labels' => array_column($exp, 'name'), 'values' => array_map(static fn ($r) => (float) $r['v'], $exp)];
            $svc = $this->db->query(
                "SELECT COALESCE(s.name, li.item_name) svc_name, SUM(li.taxable_value) v FROM billing_line_items li
                 JOIN billing_documents d ON d.id = li.document_id LEFT JOIN services s ON s.id = li.service_id
                 WHERE d.status='finalized' AND d.doc_type IN ('tax_invoice','bill_of_supply','cash_memo') AND d.issue_date BETWEEN ? AND ?
                 GROUP BY svc_name ORDER BY v DESC LIMIT 8",
                [$from, $to]
            )->getResultArray();
            $charts['top_services'] = ['labels' => array_column($svc, 'svc_name'), 'values' => array_map(static fn ($r) => (float) $r['v'], $svc)];
            $aging = $this->db->query(
                "SELECT
                   SUM(CASE WHEN due_date IS NULL OR due_date >= CURDATE() THEN balance_due ELSE 0 END) b0,
                   SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 1 AND 30 THEN balance_due ELSE 0 END) b1,
                   SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 31 AND 60 THEN balance_due ELSE 0 END) b2,
                   SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 61 AND 90 THEN balance_due ELSE 0 END) b3,
                   SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) > 90 THEN balance_due ELSE 0 END) b4
                 FROM billing_documents WHERE status='finalized' AND balance_due > 0 AND doc_type IN ('tax_invoice','bill_of_supply','cash_memo','debit_note')"
            )->getRowArray();
            $charts['aging'] = ['labels' => ['Current', '1–30', '31–60', '61–90', '90+'], 'values' => array_map(static fn ($k) => (float) ($aging[$k] ?? 0), ['b0', 'b1', 'b2', 'b3', 'b4'])];
            $fy     = fy_for_date($to);
            $prevFy = fy_for_date(date('Y-m-d', strtotime($fy['start_date'] . ' -1 day')));
            $yoy    = function (array $f): array {
                $rows = $this->db->query(
                    "SELECT MONTH(issue_date) mo, SUM(CASE WHEN doc_type='credit_note' THEN -taxable_total ELSE taxable_total END) v FROM billing_documents
                     WHERE status='finalized' AND doc_type IN ('tax_invoice','bill_of_supply','cash_memo','debit_note','credit_note') AND issue_date BETWEEN ? AND ? GROUP BY mo",
                    [$f['start_date'], $f['end_date']]
                )->getResultArray();
                $map = array_column($rows, 'v', 'mo');
                $out = [];
                foreach ([4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3] as $mo) {
                    $out[] = (float) ($map[$mo] ?? 0);
                }

                return $out;
            };
            $charts['yoy'] = ['labels' => ['Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan', 'Feb', 'Mar'], 'current' => $yoy($fy), 'previous' => $yoy($prevFy), 'current_label' => 'FY ' . $fy['code'], 'previous_label' => 'FY ' . $prevFy['code']];
        }

        return $charts;
    }
}
