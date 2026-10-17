<?php

namespace App\Services\Reports;

use App\Libraries\Dec;
use App\Services\Security\Authorization;
use CodeIgniter\Database\BaseConnection;

/**
 * Business reports. Every report returns the same shape so one screen and
 * one exporter serve all of them:
 *   ['columns' => [...SheetIO columns], 'rows' => [...], 'totals' => [keys], 'notes' => [...], 'summary' => [[label, value, type]]]
 *
 * Figures come from finalized documents, receipts and the ledger — never
 * from drafts — so the same number means the same thing everywhere.
 */
class ReportService
{
    public const REPORTS = [
        'sales_register'   => ['group' => 'Sales', 'title' => 'Tax invoice register', 'perm' => 'reports.billing.view', 'desc' => 'Tax invoices and bills of supply with GST breakup, payment status and cancellations.'],
        'cash_memo_register' => ['group' => 'Sales', 'title' => 'Cash memo register', 'perm' => 'reports.billing.view', 'desc' => 'Cash memos (separate series, same GST rules).'],
        'combined_register' => ['group' => 'Sales', 'title' => 'Combined sales register', 'perm' => 'reports.billing.view', 'desc' => 'Invoices, cash memos, bills of supply, credit and debit notes (notes signed).'],
        'notes_register'   => ['group' => 'Sales', 'title' => 'Credit / debit note register', 'perm' => 'reports.billing.view', 'desc' => 'Notes with the original invoice and reason.'],
        'service_revenue'  => ['group' => 'Sales', 'title' => 'Revenue by service', 'perm' => 'reports.billing.view', 'desc' => 'Taxable value by service/SAC, net of credit notes.'],
        'client_revenue'   => ['group' => 'Sales', 'title' => 'Revenue by client', 'perm' => 'reports.billing.view', 'desc' => 'Billed, collected and outstanding per client.'],
        'collections'      => ['group' => 'Money', 'title' => 'Collections register', 'perm' => 'reports.finance.view', 'desc' => 'Receipts and refunds by mode and account (no double counting with sales).'],
        'aging'            => ['group' => 'Money', 'title' => 'Receivables aging', 'perm' => 'reports.finance.view', 'desc' => 'Unpaid balances by days overdue, as on today.'],
        'cash_flow'        => ['group' => 'Money', 'title' => 'Cash flow', 'perm' => 'reports.finance.view', 'desc' => 'Month-wise money in/out of cash and bank accounts. Internal transfers excluded.'],
        'profit_loss'      => ['group' => 'Money', 'title' => 'Profit & loss (accrual)', 'perm' => 'reports.finance.view', 'desc' => 'Income and expense ledgers for the period.'],
        'expense_summary'  => ['group' => 'Money', 'title' => 'Expenses by category', 'perm' => 'reports.finance.view', 'desc' => 'Approved expenses with GST and ITC by category.'],
        'fy_comparison'    => ['group' => 'Money', 'title' => 'Financial-year comparison', 'perm' => 'reports.finance.view', 'desc' => 'Sales, collections and expenses month by month: this FY vs last FY.'],
        'gst_output'       => ['group' => 'GST', 'title' => 'GST output tax summary', 'perm' => 'reports.gst.view', 'desc' => 'Output tax by GST type and rate, net of notes.'],
        'gst_itc'          => ['group' => 'GST', 'title' => 'Input tax credit register', 'perm' => 'reports.gst.view', 'desc' => 'Approved expenses with input tax, eligible and blocked credit.'],
        'audit_exceptions' => ['group' => 'Control', 'title' => 'Audit exceptions', 'perm' => 'reports.audit.view', 'desc' => 'Cancellations, tax-review overrides, voids, personal-account receipts, manual journals.'],
        'downloads'        => ['group' => 'Control', 'title' => 'Report download history', 'perm' => 'reports.history.view', 'desc' => 'Who exported which report, when.'],
    ];

    public function __construct(private readonly BaseConnection $db, private readonly Authorization $authz)
    {
    }

    /** @return array<string, array<string, string>> reports the current user may open */
    public function available(): array
    {
        return array_filter(self::REPORTS, fn ($r) => $this->authz->can($r['perm']));
    }

    /** @return array<string, mixed> */
    public function run(string $code, string $from, string $to): array
    {
        return match ($code) {
            'sales_register'     => $this->register(['tax_invoice', 'bill_of_supply'], $from, $to),
            'cash_memo_register' => $this->register(['cash_memo'], $from, $to),
            'combined_register'  => $this->register(['tax_invoice', 'bill_of_supply', 'cash_memo', 'credit_note', 'debit_note'], $from, $to),
            'notes_register'     => $this->register(['credit_note', 'debit_note'], $from, $to),
            'service_revenue'    => $this->serviceRevenue($from, $to),
            'client_revenue'     => $this->clientRevenue($from, $to),
            'collections'        => $this->collections($from, $to),
            'aging'              => $this->aging(),
            'cash_flow'          => $this->cashFlow($from, $to),
            'profit_loss'        => $this->profitLoss($from, $to),
            'expense_summary'    => $this->expenseSummary($from, $to),
            'fy_comparison'      => $this->fyComparison($to),
            'gst_output'         => $this->gstOutput($from, $to),
            'gst_itc'            => $this->gstItc($from, $to),
            'audit_exceptions'   => $this->auditExceptions($from, $to),
            'downloads'          => $this->downloads($from, $to),
        };
    }

    private const M = ['type' => 'money'];

    /** @param list<string> $types */
    private function register(array $types, string $from, string $to): array
    {
        $rows = $this->db->table('billing_documents d')
            ->select('d.id, d.doc_type, d.document_number, d.issue_date, d.status, d.bill_to_name, d.bill_to_gstin, d.place_of_supply_state_code AS pos, d.gst_type,
                d.taxable_total, d.cgst_total, d.sgst_total, d.utgst_total, d.igst_total, d.cess_total, d.round_off, d.grand_total, d.amount_paid, d.balance_due,
                d.payment_status, d.cancel_reason, o.document_number AS original_number, n.reason_text')
            ->join('billing_documents o', 'o.id = d.original_document_id', 'left')
            ->join('credit_debit_note_links n', 'n.note_document_id = d.id', 'left')
            ->whereIn('d.doc_type', $types)->whereIn('d.status', ['finalized', 'cancelled'])
            ->where('d.issue_date >=', $from)->where('d.issue_date <=', $to)
            ->orderBy('d.issue_date')->orderBy('d.document_number')->get()->getResultArray();
        $amountKeys = ['taxable_total', 'cgst_total', 'sgst_total', 'utgst_total', 'igst_total', 'cess_total', 'round_off', 'grand_total', 'amount_paid', 'balance_due'];
        $cancelled  = 0;
        foreach ($rows as &$r) {
            $r['type_label'] = doc_type_label($r['doc_type']);
            $r['gst_label']  = gst_type_label($r['gst_type']);
            $sign = $r['doc_type'] === 'credit_note' && count($types) > 2 ? -1 : 1;
            if ($r['status'] === 'cancelled') {
                $cancelled++;
                $r['status_label'] = 'CANCELLED (was ' . inr($r['grand_total']) . ')' . ($r['cancel_reason'] ? ': ' . $r['cancel_reason'] : '');
                foreach ($amountKeys as $k) {
                    $r[$k] = '0.00';
                }
            } else {
                $r['status_label'] = $r['payment_status'] === 'na' ? 'finalized' : $r['payment_status'];
                if ($sign < 0) {
                    foreach ($amountKeys as $k) {
                        $r[$k] = Dec::neg($r[$k]);
                    }
                }
            }
        }
        unset($r);
        $cols = [['key' => 'document_number', 'label' => 'Number'], ['key' => 'issue_date', 'label' => 'Date', 'type' => 'date']];
        if (count($types) > 1) {
            $cols[] = ['key' => 'type_label', 'label' => 'Type'];
        }
        $cols = array_merge($cols, [
            ['key' => 'bill_to_name', 'label' => 'Customer', 'width' => 30], ['key' => 'bill_to_gstin', 'label' => 'GSTIN'], ['key' => 'pos', 'label' => 'PoS'], ['key' => 'gst_label', 'label' => 'GST type', 'width' => 24],
        ]);
        if (array_intersect($types, ['credit_note', 'debit_note'])) {
            $cols[] = ['key' => 'original_number', 'label' => 'Against'];
            $cols[] = ['key' => 'reason_text', 'label' => 'Reason', 'width' => 28];
        }
        $cols = array_merge($cols, [
            ['key' => 'taxable_total', 'label' => 'Taxable'] + self::M, ['key' => 'cgst_total', 'label' => 'CGST'] + self::M, ['key' => 'sgst_total', 'label' => 'SGST'] + self::M,
            ['key' => 'utgst_total', 'label' => 'UTGST'] + self::M, ['key' => 'igst_total', 'label' => 'IGST'] + self::M, ['key' => 'cess_total', 'label' => 'Cess'] + self::M,
            ['key' => 'round_off', 'label' => 'Round-off'] + self::M, ['key' => 'grand_total', 'label' => 'Total'] + self::M,
            ['key' => 'amount_paid', 'label' => 'Paid'] + self::M, ['key' => 'balance_due', 'label' => 'Balance'] + self::M, ['key' => 'status_label', 'label' => 'Status', 'width' => 24],
        ]);
        $sum = static fn (string $k) => Dec::add('0', ...array_column($rows, $k));

        return [
            'columns' => $cols, 'rows' => $rows, 'totals' => $amountKeys,
            'summary' => [['Documents', (string) count($rows), 'int'], ['Cancelled (excluded)', (string) $cancelled, 'int'], ['Taxable value', $sum('taxable_total'), 'money'],
                ['GST', Dec::add($sum('cgst_total'), $sum('sgst_total'), $sum('utgst_total'), $sum('igst_total'), $sum('cess_total')), 'money'], ['Total', $sum('grand_total'), 'money']],
            'notes' => array_filter([
                'Finalized documents only; drafts and estimates are never included. Cancelled numbers are listed with zero amounts so the series has no gaps.',
                count($types) > 2 ? 'Credit notes are shown as negative amounts.' : null,
            ]),
        ];
    }

    private function serviceRevenue(string $from, string $to): array
    {
        $rows = $this->db->query(
            "SELECT COALESCE(s.name, l.item_name) AS service, COALESCE(l.sac_code, '') AS sac,
                    SUM(CASE WHEN d.doc_type = 'credit_note' THEN -l.qty ELSE l.qty END) AS qty,
                    SUM(CASE WHEN d.doc_type = 'credit_note' THEN -l.taxable_value ELSE l.taxable_value END) AS taxable,
                    SUM(CASE WHEN d.doc_type = 'credit_note' THEN -(l.cgst_amount + l.sgst_amount + l.utgst_amount + l.igst_amount + l.cess_amount) ELSE (l.cgst_amount + l.sgst_amount + l.utgst_amount + l.igst_amount + l.cess_amount) END) AS tax,
                    COUNT(DISTINCT d.id) AS documents
               FROM billing_line_items l JOIN billing_documents d ON d.id = l.document_id LEFT JOIN services s ON s.id = l.service_id
              WHERE d.status = 'finalized' AND d.doc_type IN ('tax_invoice','bill_of_supply','cash_memo','credit_note','debit_note') AND d.issue_date BETWEEN ? AND ?
              GROUP BY COALESCE(s.name, l.item_name), COALESCE(l.sac_code, '') ORDER BY taxable DESC",
            [$from, $to]
        )->getResultArray();
        $total = Dec::add('0', ...array_column($rows, 'taxable'));
        foreach ($rows as &$r) {
            $r['share'] = Dec::isPositive($total) ? Dec::div(Dec::mul($r['taxable'], '100', 6), $total, 2) : '0';
        }

        return ['columns' => [['key' => 'service', 'label' => 'Service', 'width' => 36], ['key' => 'sac', 'label' => 'SAC'], ['key' => 'documents', 'label' => 'Documents', 'type' => 'int'],
            ['key' => 'qty', 'label' => 'Qty', 'type' => 'number'], ['key' => 'taxable', 'label' => 'Taxable value'] + self::M, ['key' => 'tax', 'label' => 'GST'] + self::M, ['key' => 'share', 'label' => 'Share %', 'type' => 'percent']],
            'rows' => $rows, 'totals' => ['taxable', 'tax'], 'summary' => [['Taxable revenue', $total, 'money']], 'notes' => ['Revenue is the taxable value (excluding GST), net of credit notes.']];
    }

    private function clientRevenue(string $from, string $to): array
    {
        $rows = $this->db->query(
            "SELECT c.id, c.name, c.gstin,
                    COALESCE(SUM(CASE WHEN d.doc_type = 'credit_note' THEN -d.taxable_total ELSE d.taxable_total END),0) AS taxable,
                    COALESCE(SUM(CASE WHEN d.doc_type = 'credit_note' THEN -d.grand_total ELSE d.grand_total END),0) AS billed,
                    COUNT(d.id) AS documents
               FROM billing_documents d JOIN contacts c ON c.id = d.contact_id
              WHERE d.status = 'finalized' AND d.doc_type IN ('tax_invoice','bill_of_supply','cash_memo','credit_note','debit_note') AND d.issue_date BETWEEN ? AND ?
              GROUP BY c.id, c.name, c.gstin ORDER BY taxable DESC",
            [$from, $to]
        )->getResultArray();
        $collected = array_column($this->db->query(
            "SELECT contact_id, SUM(CASE WHEN receipt_type = 'refund' THEN -amount ELSE amount END) amt FROM payment_receipts WHERE status = 'active' AND receipt_date BETWEEN ? AND ? GROUP BY contact_id",
            [$from, $to]
        )->getResultArray(), 'amt', 'contact_id');
        $outstanding = array_column($this->db->query(
            "SELECT contact_id, SUM(balance_due) amt FROM billing_documents WHERE status = 'finalized' AND doc_type IN ('tax_invoice','bill_of_supply','cash_memo','debit_note') GROUP BY contact_id"
        )->getResultArray(), 'amt', 'contact_id');
        foreach ($rows as &$r) {
            $r['collected']   = Dec::round($collected[$r['id']] ?? '0');
            $r['outstanding'] = Dec::round($outstanding[$r['id']] ?? '0');
        }

        return ['columns' => [['key' => 'name', 'label' => 'Client', 'width' => 34], ['key' => 'gstin', 'label' => 'GSTIN'], ['key' => 'documents', 'label' => 'Documents', 'type' => 'int'],
            ['key' => 'taxable', 'label' => 'Taxable value'] + self::M, ['key' => 'billed', 'label' => 'Billed (incl. GST)'] + self::M, ['key' => 'collected', 'label' => 'Collected in period'] + self::M,
            ['key' => 'outstanding', 'label' => 'Outstanding today'] + self::M],
            'rows' => $rows, 'totals' => ['taxable', 'billed', 'collected', 'outstanding'], 'notes' => ['Collected = receipts − refunds dated in the period. Outstanding is the current unpaid balance across all periods.']];
    }

    private function collections(string $from, string $to): array
    {
        $rows = $this->db->table('payment_receipts r')
            ->select('r.receipt_number, r.receipt_type, r.receipt_date, r.mode, r.reference_no, r.amount, r.allocated_amount, r.unapplied_amount, r.status, r.reconciliation_status, c.name AS customer, a.display_name AS account, a.ownership')
            ->join('contacts c', 'c.id = r.contact_id')->join('bank_cash_accounts a', 'a.id = r.bank_cash_account_id')
            ->where('r.receipt_date >=', $from)->where('r.receipt_date <=', $to)->orderBy('r.receipt_date')->orderBy('r.id')->get()->getResultArray();
        $byMode = [];
        foreach ($rows as &$r) {
            $active = $r['status'] === 'active';
            $r['net'] = ! $active ? '0.00' : ($r['receipt_type'] === 'refund' ? Dec::neg($r['amount']) : $r['amount']);
            $r['flag'] = trim(($active ? '' : 'VOID ') . ($r['ownership'] === 'owner_personal' ? 'personal account' : ''));
            if ($active) {
                $byMode[$r['mode']] = Dec::add($byMode[$r['mode']] ?? '0', $r['net']);
            }
        }
        unset($r);
        $summary = [['Net collections', Dec::add('0', ...array_column($rows, 'net')), 'money']];
        foreach ($byMode as $mode => $amt) {
            $summary[] = [ucwords(str_replace('_', ' ', $mode)), $amt, 'money'];
        }

        return ['columns' => [['key' => 'receipt_number', 'label' => 'Voucher'], ['key' => 'receipt_type', 'label' => 'Type'], ['key' => 'receipt_date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'customer', 'label' => 'Customer', 'width' => 28], ['key' => 'mode', 'label' => 'Mode'], ['key' => 'account', 'label' => 'Account', 'width' => 22], ['key' => 'reference_no', 'label' => 'Reference'],
            ['key' => 'net', 'label' => 'Amount'] + self::M, ['key' => 'unapplied_amount', 'label' => 'Unapplied'] + self::M, ['key' => 'reconciliation_status', 'label' => 'Bank recon'], ['key' => 'flag', 'label' => 'Flag']],
            'rows' => $rows, 'totals' => ['net'], 'summary' => $summary,
            'notes' => ['Collections are money received, not sales: a receipt settles an invoice that was already counted when it was finalized. Cash deposits to bank are transfers and are not listed here.']];
    }

    private function aging(): array
    {
        $today = date('Y-m-d');
        $rows  = $this->db->table('billing_documents d')->select('d.document_number, d.doc_type, d.issue_date, d.due_date, d.grand_total, d.balance_due, c.name AS customer, c.phone')
            ->join('contacts c', 'c.id = d.contact_id')->where('d.status', 'finalized')->whereIn('d.doc_type', ['tax_invoice', 'bill_of_supply', 'cash_memo', 'debit_note'])
            ->where('d.balance_due >', 0)->orderBy('c.name')->orderBy('d.issue_date')->get()->getResultArray();
        $buckets = ['current' => '0', 'd30' => '0', 'd60' => '0', 'd90' => '0', 'd90p' => '0'];
        foreach ($rows as &$r) {
            $due  = $r['due_date'] ?: $r['issue_date'];
            $days = (int) floor((strtotime($today) - strtotime($due)) / 86400);
            $r['days_overdue'] = max(0, $days);
            $b = match (true) { $days <= 0 => 'current', $days <= 30 => 'd30', $days <= 60 => 'd60', $days <= 90 => 'd90', default => 'd90p' };
            foreach (array_keys($buckets) as $k) {
                $r[$k] = $k === $b ? $r['balance_due'] : null;
            }
            $buckets[$b] = Dec::add($buckets[$b], $r['balance_due']);
        }
        unset($r);

        return ['columns' => [['key' => 'customer', 'label' => 'Customer', 'width' => 28], ['key' => 'phone', 'label' => 'Phone'], ['key' => 'document_number', 'label' => 'Document'],
            ['key' => 'issue_date', 'label' => 'Date', 'type' => 'date'], ['key' => 'due_date', 'label' => 'Due', 'type' => 'date'], ['key' => 'days_overdue', 'label' => 'Days overdue', 'type' => 'int'],
            ['key' => 'current', 'label' => 'Not yet due'] + self::M, ['key' => 'd30', 'label' => '1–30'] + self::M, ['key' => 'd60', 'label' => '31–60'] + self::M,
            ['key' => 'd90', 'label' => '61–90'] + self::M, ['key' => 'd90p', 'label' => '> 90'] + self::M, ['key' => 'balance_due', 'label' => 'Balance'] + self::M],
            'rows' => $rows, 'totals' => ['current', 'd30', 'd60', 'd90', 'd90p', 'balance_due'],
            'summary' => [['Total receivable', Dec::add('0', ...array_values($buckets)), 'money'], ['Overdue > 90 days', $buckets['d90p'], 'money']],
            'notes' => ['As on ' . dmy($today) . '. Unapplied advances are not deducted here; see Payments & receipts.']];
    }

    private function cashFlow(string $from, string $to): array
    {
        $rows = $this->db->query(
            "SELECT DATE_FORMAT(je.entry_date, '%Y-%m') AS month, je.source_type, SUM(jl.debit) AS inflow, SUM(jl.credit) AS outflow
               FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
              WHERE jl.bank_cash_account_id IS NOT NULL AND je.entry_date BETWEEN ? AND ? AND je.source_type NOT IN ('money_transfer', 'opening_balance')
              GROUP BY DATE_FORMAT(je.entry_date, '%Y-%m'), je.source_type ORDER BY month",
            [$from, $to]
        )->getResultArray();
        $out = [];
        foreach ($rows as $r) {
            $m = $r['month'];
            $out[$m] ??= ['month' => date('M Y', strtotime($m . '-01')), 'receipts' => '0', 'refunds' => '0', 'expenses' => '0', 'salaries' => '0', 'other_in' => '0', 'other_out' => '0'];
            switch ($r['source_type']) {
                case 'payment_receipt': $out[$m]['receipts'] = Dec::add($out[$m]['receipts'], Dec::sub($r['inflow'], $r['outflow'])); break;
                case 'refund': $out[$m]['refunds'] = Dec::add($out[$m]['refunds'], Dec::sub($r['outflow'], $r['inflow'])); break;
                case 'expense_payment': $out[$m]['expenses'] = Dec::add($out[$m]['expenses'], Dec::sub($r['outflow'], $r['inflow'])); break;
                case 'payroll_payment': case 'payroll_run': $out[$m]['salaries'] = Dec::add($out[$m]['salaries'], Dec::sub($r['outflow'], $r['inflow'])); break;
                default:
                    $out[$m]['other_in']  = Dec::add($out[$m]['other_in'], $r['inflow']);
                    $out[$m]['other_out'] = Dec::add($out[$m]['other_out'], $r['outflow']);
            }
        }
        foreach ($out as &$o) {
            $o['net'] = Dec::sub(Dec::add($o['receipts'], $o['other_in']), Dec::add($o['refunds'], $o['expenses'], $o['salaries'], $o['other_out']));
        }
        unset($o);

        return ['columns' => [['key' => 'month', 'label' => 'Month'], ['key' => 'receipts', 'label' => 'Customer receipts'] + self::M, ['key' => 'other_in', 'label' => 'Other in'] + self::M,
            ['key' => 'refunds', 'label' => 'Refunds'] + self::M, ['key' => 'expenses', 'label' => 'Expense payments'] + self::M, ['key' => 'salaries', 'label' => 'Salaries'] + self::M,
            ['key' => 'other_out', 'label' => 'Other out'] + self::M, ['key' => 'net', 'label' => 'Net cash flow'] + self::M],
            'rows' => array_values($out), 'totals' => ['receipts', 'other_in', 'refunds', 'expenses', 'salaries', 'other_out', 'net'],
            'notes' => ['Movements in cash, bank and UPI accounts. Deposits and transfers between your own accounts are excluded (they are not income or expense). "Other" includes manual journals and items booked from bank statements.']];
    }

    private function profitLoss(string $from, string $to): array
    {
        $rows = $this->db->query(
            "SELECT la.code, la.name, la.account_group, COALESCE(SUM(jl.credit - jl.debit),0) AS amount
               FROM ledger_accounts la JOIN journal_lines jl ON jl.ledger_account_id = la.id JOIN journal_entries je ON je.id = jl.journal_entry_id
              WHERE la.account_group IN ('income','expense') AND je.entry_date BETWEEN ? AND ?
              GROUP BY la.code, la.name, la.account_group ORDER BY FIELD(la.account_group,'income','expense'), la.code",
            [$from, $to]
        )->getResultArray();
        $income  = '0';
        $expense = '0';
        foreach ($rows as &$r) {
            if ($r['account_group'] === 'expense') {
                $r['amount'] = Dec::neg($r['amount']);
                $expense = Dec::add($expense, $r['amount']);
            } else {
                $income = Dec::add($income, $r['amount']);
            }
            $r['group'] = $r['account_group'] === 'income' ? 'Income' : 'Expense';
        }
        unset($r);
        $rows[] = ['code' => '', 'name' => 'Total income', 'group' => '', 'amount' => $income];
        $rows[] = ['code' => '', 'name' => 'Total expenses', 'group' => '', 'amount' => $expense];
        $rows[] = ['code' => '', 'name' => Dec::isNegative(Dec::sub($income, $expense)) ? 'Net loss' : 'Net profit', 'group' => '', 'amount' => Dec::sub($income, $expense)];

        return ['columns' => [['key' => 'group', 'label' => 'Group'], ['key' => 'code', 'label' => 'Code'], ['key' => 'name', 'label' => 'Ledger', 'width' => 40], ['key' => 'amount', 'label' => 'Amount'] + self::M],
            'rows' => $rows, 'summary' => [['Income', $income, 'money'], ['Expenses', $expense, 'money'], ['Net', Dec::sub($income, $expense), 'money']],
            'notes' => ['Accrual basis from the journal: sales when invoices are finalized (net of credit notes), expenses when approved, salaries when payroll is posted. GST is excluded (it sits in tax ledgers).', 'Not an audited statement.']];
    }

    private function expenseSummary(string $from, string $to): array
    {
        $rows = $this->db->query(
            "SELECT c.name AS category, COUNT(*) AS count, SUM(e.taxable_amount) AS taxable, SUM(e.cgst_amount + e.sgst_amount + e.utgst_amount + e.igst_amount) AS gst,
                    SUM(e.itc_eligible_amount) AS itc, SUM(e.itc_ineligible_amount) AS itc_blocked, SUM(e.total_amount) AS total, SUM(e.total_amount - e.paid_amount) AS unpaid
               FROM expenses e JOIN expense_categories c ON c.id = e.category_id
              WHERE e.approval_status = 'approved' AND e.deleted_at IS NULL AND e.expense_date BETWEEN ? AND ?
              GROUP BY c.name ORDER BY total DESC",
            [$from, $to]
        )->getResultArray();

        return ['columns' => [['key' => 'category', 'label' => 'Category', 'width' => 30], ['key' => 'count', 'label' => 'Bills', 'type' => 'int'], ['key' => 'taxable', 'label' => 'Before GST'] + self::M,
            ['key' => 'gst', 'label' => 'GST'] + self::M, ['key' => 'itc', 'label' => 'ITC eligible'] + self::M, ['key' => 'itc_blocked', 'label' => 'ITC not eligible'] + self::M,
            ['key' => 'total', 'label' => 'Total'] + self::M, ['key' => 'unpaid', 'label' => 'Unpaid'] + self::M],
            'rows' => $rows, 'totals' => ['count', 'taxable', 'gst', 'itc', 'itc_blocked', 'total', 'unpaid'], 'notes' => ['Approved expenses only. Pending approvals are not counted.']];
    }

    private function fyComparison(string $asOf): array
    {
        $fy   = fy_for_date($asOf);
        $prev = fy_for_date(date('Y-m-d', strtotime($fy['start_date'] . ' -1 day')));
        $series = function (string $sql, string $from, string $to): array {
            return array_column($this->db->query($sql, [$from, $to])->getResultArray(), 'amt', 'm');
        };
        $salesSql = "SELECT DATE_FORMAT(issue_date,'%m') m, SUM(CASE WHEN doc_type = 'credit_note' THEN -taxable_total ELSE taxable_total END) amt FROM billing_documents
                     WHERE status = 'finalized' AND doc_type IN ('tax_invoice','bill_of_supply','cash_memo','credit_note','debit_note') AND issue_date BETWEEN ? AND ? GROUP BY DATE_FORMAT(issue_date,'%m')";
        $collSql  = "SELECT DATE_FORMAT(receipt_date,'%m') m, SUM(CASE WHEN receipt_type = 'refund' THEN -amount ELSE amount END) amt FROM payment_receipts
                     WHERE status = 'active' AND receipt_date BETWEEN ? AND ? GROUP BY DATE_FORMAT(receipt_date,'%m')";
        $expSql   = "SELECT DATE_FORMAT(expense_date,'%m') m, SUM(total_amount) amt FROM expenses WHERE approval_status = 'approved' AND deleted_at IS NULL AND expense_date BETWEEN ? AND ? GROUP BY DATE_FORMAT(expense_date,'%m')";
        $cur = ['s' => $series($salesSql, $fy['start_date'], $fy['end_date']), 'c' => $series($collSql, $fy['start_date'], $fy['end_date']), 'e' => $series($expSql, $fy['start_date'], $fy['end_date'])];
        $old = ['s' => $series($salesSql, $prev['start_date'], $prev['end_date']), 'c' => $series($collSql, $prev['start_date'], $prev['end_date']), 'e' => $series($expSql, $prev['start_date'], $prev['end_date'])];
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $m  = date('m', strtotime($fy['start_date'] . " +{$i} months"));
            $rows[] = ['month' => date('M', strtotime($fy['start_date'] . " +{$i} months")),
                'sales_prev' => Dec::round($old['s'][$m] ?? '0'), 'sales_cur' => Dec::round($cur['s'][$m] ?? '0'),
                'coll_prev' => Dec::round($old['c'][$m] ?? '0'), 'coll_cur' => Dec::round($cur['c'][$m] ?? '0'),
                'exp_prev' => Dec::round($old['e'][$m] ?? '0'), 'exp_cur' => Dec::round($cur['e'][$m] ?? '0')];
        }

        return ['columns' => [['key' => 'month', 'label' => 'Month'], ['key' => 'sales_prev', 'label' => 'Sales FY ' . $prev['code']] + self::M, ['key' => 'sales_cur', 'label' => 'Sales FY ' . $fy['code']] + self::M,
            ['key' => 'coll_prev', 'label' => 'Collections FY ' . $prev['code']] + self::M, ['key' => 'coll_cur', 'label' => 'Collections FY ' . $fy['code']] + self::M,
            ['key' => 'exp_prev', 'label' => 'Expenses FY ' . $prev['code']] + self::M, ['key' => 'exp_cur', 'label' => 'Expenses FY ' . $fy['code']] + self::M],
            'rows' => $rows, 'totals' => ['sales_prev', 'sales_cur', 'coll_prev', 'coll_cur', 'exp_prev', 'exp_cur'],
            'notes' => ['Sales = taxable value net of credit notes. Collections = receipts − refunds. Expenses = approved totals.'], 'chart' => true];
    }

    private function gstOutput(string $from, string $to): array
    {
        $rows = (new GstReturnService($this->db))->outputSummary($from, $to);

        return ['columns' => [['key' => 'gst_type', 'label' => 'GST type', 'width' => 26], ['key' => 'treatment', 'label' => 'Treatment'], ['key' => 'rate', 'label' => 'Rate %', 'type' => 'percent'],
            ['key' => 'taxable', 'label' => 'Taxable'] + self::M, ['key' => 'cgst', 'label' => 'CGST'] + self::M, ['key' => 'sgst', 'label' => 'SGST'] + self::M, ['key' => 'utgst', 'label' => 'UTGST'] + self::M,
            ['key' => 'igst', 'label' => 'IGST'] + self::M, ['key' => 'cess', 'label' => 'Cess'] + self::M, ['key' => 'tax', 'label' => 'Total tax'] + self::M],
            'rows' => $rows, 'totals' => ['taxable', 'cgst', 'sgst', 'utgst', 'igst', 'cess', 'tax'], 'notes' => ['Finalized tax invoices, cash memos, bills of supply and notes (credit notes negative).']];
    }

    private function gstItc(string $from, string $to): array
    {
        $rows = (new GstReturnService($this->db))->itcRegister($from, $to);

        return ['columns' => [['key' => 'expense_number', 'label' => 'Expense'], ['key' => 'expense_date', 'label' => 'Date', 'type' => 'date'], ['key' => 'vendor_name', 'label' => 'Vendor', 'width' => 26],
            ['key' => 'vendor_gstin', 'label' => 'Vendor GSTIN'], ['key' => 'vendor_invoice_no', 'label' => 'Invoice'], ['key' => 'vendor_invoice_date', 'label' => 'Invoice date', 'type' => 'date'],
            ['key' => 'category', 'label' => 'Category'], ['key' => 'taxable_amount', 'label' => 'Taxable'] + self::M, ['key' => 'cgst_amount', 'label' => 'CGST'] + self::M,
            ['key' => 'sgst_amount', 'label' => 'SGST'] + self::M, ['key' => 'utgst_amount', 'label' => 'UTGST'] + self::M, ['key' => 'igst_amount', 'label' => 'IGST'] + self::M,
            ['key' => 'itc_eligible_amount', 'label' => 'ITC eligible'] + self::M, ['key' => 'itc_ineligible_amount', 'label' => 'ITC not eligible'] + self::M, ['key' => 'itc_rule', 'label' => 'ITC rule', 'width' => 30]],
            'rows' => $rows, 'totals' => ['taxable_amount', 'cgst_amount', 'sgst_amount', 'utgst_amount', 'igst_amount', 'itc_eligible_amount', 'itc_ineligible_amount'],
            'notes' => ['Approved expenses only. Match with GSTR-2B before claiming; ITC on bills missing from 2B must be deferred.']];
    }

    private function auditExceptions(string $from, string $to): array
    {
        $rows = [];
        $add  = static function (string $type, string $date, string $ref, string $detail, ?string $amount = null) use (&$rows): void {
            $rows[] = ['type' => $type, 'date' => $date, 'reference' => $ref, 'detail' => $detail, 'amount' => $amount];
        };
        foreach ($this->db->table('billing_documents')->select('document_number, cancelled_at, cancel_reason, grand_total')->where('status', 'cancelled')->where('cancelled_at >=', $from . ' 00:00:00')->where('cancelled_at <=', $to . ' 23:59:59')->get()->getResultArray() as $r) {
            $add('Document cancelled', substr($r['cancelled_at'], 0, 10), (string) $r['document_number'], (string) $r['cancel_reason'], $r['grand_total']);
        }
        foreach ($this->db->table('billing_documents')->select('document_number, finalized_at, tax_review_reason, grand_total')->where('tax_review_status', 'resolved')->where('finalized_at >=', $from . ' 00:00:00')->where('finalized_at <=', $to . ' 23:59:59')->get()->getResultArray() as $r) {
            $add('Tax review override', substr((string) $r['finalized_at'], 0, 10), (string) $r['document_number'], (string) $r['tax_review_reason'], $r['grand_total']);
        }
        foreach ($this->db->table('payment_receipts')->select('receipt_number, voided_at, void_reason, amount')->where('status', 'void')->where('voided_at >=', $from . ' 00:00:00')->where('voided_at <=', $to . ' 23:59:59')->get()->getResultArray() as $r) {
            $add('Receipt voided', substr($r['voided_at'], 0, 10), $r['receipt_number'], (string) $r['void_reason'], $r['amount']);
        }
        foreach ($this->db->table('payment_receipts r')->select('r.receipt_number, r.receipt_date, r.amount, a.display_name')->join('bank_cash_accounts a', 'a.id = r.bank_cash_account_id')
            ->where('a.ownership', 'owner_personal')->where('r.status', 'active')->where('r.receipt_date >=', $from)->where('r.receipt_date <=', $to)->get()->getResultArray() as $r) {
            $add('Receipt into owner personal account', $r['receipt_date'], $r['receipt_number'], $r['display_name'], $r['amount']);
        }
        foreach ($this->db->table('journal_entries')->select('entry_no, entry_date, narration, total_debit')->where('source_type', 'manual')->where('entry_date >=', $from)->where('entry_date <=', $to)->get()->getResultArray() as $r) {
            $add('Manual journal', $r['entry_date'], $r['entry_no'], $r['narration'], $r['total_debit']);
        }
        foreach ($this->db->table('money_transfers')->select('transfer_number, voided_at, void_reason, amount')->where('status', 'void')->where('voided_at >=', $from . ' 00:00:00')->where('voided_at <=', $to . ' 23:59:59')->get()->getResultArray() as $r) {
            $add('Transfer voided', substr($r['voided_at'], 0, 10), $r['transfer_number'], (string) $r['void_reason'], $r['amount']);
        }
        foreach ($this->db->table('expenses')->select('expense_number, approved_at, total_amount')->where('approval_status', 'approved')->where('approved_by = created_by', null, false)->where('approved_at >=', $from . ' 00:00:00')->where('approved_at <=', $to . ' 23:59:59')->get()->getResultArray() as $r) {
            $add('Expense approved by its recorder (owner)', substr($r['approved_at'], 0, 10), $r['expense_number'], 'Separation of duties bypassed by super admin', $r['total_amount']);
        }
        foreach ($this->db->table('audit_logs')->select('occurred_at, summary')->where('action', 'access.denied')->where('occurred_at >=', $from . ' 00:00:00')->where('occurred_at <=', $to . ' 23:59:59')->orderBy('id', 'DESC')->limit(200)->get()->getResultArray() as $r) {
            $add('Access denied', substr($r['occurred_at'], 0, 10), '', (string) $r['summary']);
        }
        usort($rows, static fn ($a, $b) => strcmp($a['date'], $b['date']));

        return ['columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'type', 'label' => 'Exception', 'width' => 30], ['key' => 'reference', 'label' => 'Reference'],
            ['key' => 'detail', 'label' => 'Detail / reason', 'width' => 50], ['key' => 'amount', 'label' => 'Amount'] + self::M],
            'rows' => $rows, 'notes' => ['Review each item. The full tamper-evident trail is in Administration → Audit log.']];
    }

    private function downloads(string $from, string $to): array
    {
        $rows = $this->db->table('report_exports x')->select('x.created_at, x.report_code, x.format, x.row_count, x.filters, p.full_name')
            ->join('user_profiles p', 'p.user_id = x.user_id', 'left')->where('x.created_at >=', $from . ' 00:00:00')->where('x.created_at <=', $to . ' 23:59:59')
            ->orderBy('x.id', 'DESC')->limit(1000)->get()->getResultArray();

        return ['columns' => [['key' => 'created_at', 'label' => 'When', 'width' => 18], ['key' => 'full_name', 'label' => 'User', 'width' => 22], ['key' => 'report_code', 'label' => 'Report', 'width' => 24],
            ['key' => 'format', 'label' => 'Format'], ['key' => 'row_count', 'label' => 'Rows', 'type' => 'int'], ['key' => 'filters', 'label' => 'Filters', 'width' => 50]], 'rows' => $rows];
    }
}
