<?php

namespace App\Controllers;

use App\Services\Reports\GstReturnService;
use App\Services\Reports\ReportService;

/** Reports hub, report screens/exports, GST working papers and the daily digest. */
class ReportsController extends BaseController
{
    private function reports(): ReportService
    {
        return new ReportService($this->db, $this->authz);
    }

    /** @return array{0: string, 1: string, 2: string} [from, to, preset] */
    private function period(): array
    {
        $preset = (string) ($this->request->getGet('period') ?: 'this_month');
        $today  = date('Y-m-d');
        $fy     = fy_for_date($today);
        // Indian FY quarters (Apr–Jun, Jul–Sep, Oct–Dec, Jan–Mar) coincide with calendar quarters.
        $qStart = static fn (string $d): string => date('Y', strtotime($d)) . '-' . str_pad((string) (intdiv((int) date('n', strtotime($d)) - 1, 3) * 3 + 1), 2, '0', STR_PAD_LEFT) . '-01';
        [$from, $to] = match ($preset) {
            'last_month'   => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
            'this_quarter' => (static function () use ($today, $qStart) { $s = $qStart($today); return [$s, date('Y-m-t', strtotime($s . ' +2 months'))]; })(),
            'last_quarter' => (static function () use ($today, $qStart) { $s = date('Y-m-d', strtotime($qStart($today) . ' -3 months')); return [$s, date('Y-m-t', strtotime($s . ' +2 months'))]; })(),
            'this_fy'      => [$fy['start_date'], $fy['end_date']],
            'last_fy'      => (static function () use ($fy) { $p = fy_for_date(date('Y-m-d', strtotime($fy['start_date'] . ' -1 day'))); return [$p['start_date'], $p['end_date']]; })(),
            'custom'       => [ymd((string) $this->request->getGet('from')) ?? date('Y-m-01'), ymd((string) $this->request->getGet('to')) ?? $today],
            default        => [date('Y-m-01'), date('Y-m-t')],
        };
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to, $preset];
    }

    public function index()
    {
        $groups = [];
        foreach ($this->reports()->available() as $code => $r) {
            $groups[$r['group']][$code] = $r;
        }

        return $this->render('reports/index', [
            'pageTitle' => 'Reports', 'breadcrumbs' => [['Finance'], ['Reports']], 'groups' => $groups,
        ]);
    }

    public function show($code)
    {
        $code = (string) $code;
        $def  = ReportService::REPORTS[$code] ?? $this->notFound();
        $this->authorize($def['perm']);
        [$from, $to, $preset] = $this->period();
        $data = $this->reports()->run($code, $from, $to);

        if ($format = (string) $this->request->getGet('format')) {
            $this->authorize('reports.export');

            return service('exports')->download($code, $format, str_replace(' ', '_', $def['title']), [[
                'title' => mb_substr($def['title'], 0, 31), 'columns' => $data['columns'], 'rows' => $data['rows'], 'totals' => $data['totals'] ?? [], 'notes' => $data['notes'] ?? [],
            ]], ['from' => $from, 'to' => $to], ['Report' => $def['title'], 'Period' => dmy($from) . ' to ' . dmy($to)]);
        }

        return $this->render('reports/show', [
            'pageTitle' => $def['title'], 'breadcrumbs' => [['Finance'], ['Reports', 'reports'], [$def['title']]],
            'code' => $code, 'def' => $def, 'data' => $data, 'from' => $from, 'to' => $to, 'preset' => $preset,
            'noPeriod' => in_array($code, ['aging'], true),
        ]);
    }

    /** GST working papers for a month or quarter. */
    public function gst()
    {
        [$from, $to, $preset] = $this->gstPeriod();
        $svc = new GstReturnService($this->db);

        return $this->render('reports/gst', [
            'pageTitle' => 'GST', 'breadcrumbs' => [['Finance'], ['GST']],
            'from' => $from, 'to' => $to, 'preset' => $preset,
            'payable' => $svc->payable($from, $to),
            'output' => $svc->outputSummary($from, $to),
            'docs' => $svc->docsIssued($from, $to),
            'advances' => $svc->advances($to),
            'warnings' => $this->gstWarnings($from, $to),
            'months' => $this->recentMonths(),
        ]);
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function gstPeriod(): array
    {
        $m = (string) ($this->request->getGet('month') ?? '');
        if (preg_match('/^(\d{4})-(\d{2})$/', $m) && checkdate((int) substr($m, 5, 2), 1, (int) substr($m, 0, 4))) {
            return [$m . '-01', date('Y-m-t', strtotime($m . '-01')), $m];
        }
        if (preg_match('/^(\d{4})-Q([1-4])$/', $m, $q)) {
            // Indian FY quarters: Q1 Apr–Jun of the FY starting in that year
            $start = date('Y-m-d', strtotime($q[1] . '-04-01 +' . (((int) $q[2] - 1) * 3) . ' months'));

            return [$start, date('Y-m-t', strtotime($start . ' +2 months')), $m];
        }
        $last = date('Y-m', strtotime('first day of last month'));

        return [$last . '-01', date('Y-m-t', strtotime($last . '-01')), $last];
    }

    /** @return list<array{0: string, 1: string}> */
    private function recentMonths(): array
    {
        $out = [];
        for ($i = 0; $i < 15; $i++) {
            $t     = strtotime(date('Y-m-01') . " -{$i} months");
            $out[] = [date('Y-m', $t), date('M Y', $t)];
        }
        $fyStart = (int) substr(fy_for_date(date('Y-m-d'))['start_date'], 0, 4);
        foreach ([$fyStart, $fyStart - 1] as $y) {
            for ($q = 4; $q >= 1; $q--) {
                $out[] = [$y . '-Q' . $q, 'Q' . $q . ' FY ' . $y . '-' . substr((string) ($y + 1), 2)];
            }
        }

        return $out;
    }

    /** @return list<array{0: string, 1: string, 2: string|null}> [level, message, link] */
    private function gstWarnings(string $from, string $to): array
    {
        $w = [];
        $drafts = $this->db->table('billing_documents')->where('status', 'draft')->whereIn('doc_type', ['tax_invoice', 'cash_memo', 'credit_note', 'debit_note', 'bill_of_supply'])
            ->where('issue_date >=', $from)->where('issue_date <=', $to)->countAllResults();
        if ($drafts > 0) {
            $w[] = ['warning', $drafts . ' draft document(s) dated in this period are not finalized and are NOT in these figures.', site_url('billing/documents?status=draft&from=' . $from . '&to=' . $to)];
        }
        $pending = $this->db->table('expenses')->where('approval_status', 'pending')->where('deleted_at', null)->where('expense_date >=', $from)->where('expense_date <=', $to)->countAllResults();
        if ($pending > 0) {
            $w[] = ['warning', $pending . ' expense(s) in this period await approval — their ITC is not counted yet.', site_url('finance/expenses?approval=pending&from=' . $from . '&to=' . $to)];
        }
        $noSac = $this->db->table('billing_line_items l')->join('billing_documents d', 'd.id = l.document_id')->where('d.status', 'finalized')
            ->where('d.issue_date >=', $from)->where('d.issue_date <=', $to)->groupStart()->where('l.sac_code', null)->orWhere('l.sac_code', '')->groupEnd()->countAllResults();
        if ($noSac > 0) {
            $w[] = ['info', $noSac . ' finalized line(s) have no SAC code; they appear with a blank HSN in the HSN summary.', null];
        }
        $unreviewed = $this->db->table('itc_rules')->where(['review_status' => 'suggested', 'is_active' => 1])->countAllResults();
        if ($unreviewed > 0) {
            $w[] = ['info', $unreviewed . ' ITC rule(s) are still marked "suggested". Ask the accountant to review them in Settings → Master lists.', site_url('settings/masters/itc_rules')];
        }

        return $w;
    }

    public function gstr1()
    {
        $this->authorize('reports.export');
        [$from, $to, $label] = $this->gstPeriod();
        $c = $this->db->table('company_settings')->select('gstin, legal_name')->where('id', 1)->get()->getRowArray();

        return service('exports')->download('gstr1_prep', 'xlsx', 'GSTR1_prep_' . $label, (new GstReturnService($this->db))->gstr1Sheets($from, $to), ['period' => $label],
            ['Report' => 'GSTR-1 preparation workbook', 'GSTIN' => (string) ($c['gstin'] ?? ''), 'Legal name' => (string) ($c['legal_name'] ?? ''), 'Period' => dmy($from) . ' to ' . dmy($to),
                'Important' => 'Working paper prepared from DGR Office records. It is NOT a GSTN offline-tool JSON. Verify every sheet before entering or uploading on the GST portal.']);
    }

    public function gstr3b()
    {
        $this->authorize('reports.export');
        [$from, $to, $label] = $this->gstPeriod();
        $c = $this->db->table('company_settings')->select('gstin, legal_name')->where('id', 1)->get()->getRowArray();

        return service('exports')->download('gstr3b_prep', 'xlsx', 'GSTR3B_prep_' . $label, (new GstReturnService($this->db))->gstr3bSheets($from, $to), ['period' => $label],
            ['Report' => 'GSTR-3B preparation workbook', 'GSTIN' => (string) ($c['gstin'] ?? ''), 'Legal name' => (string) ($c['legal_name'] ?? ''), 'Period' => dmy($from) . ' to ' . dmy($to),
                'Important' => 'Working paper only. Reconcile ITC with GSTR-2B and confirm figures before filing.']);
    }

    /** Printable daily/period digest for the owner. */
    public function digest()
    {
        $from = ymd((string) $this->request->getGet('from')) ?? date('Y-m-d');
        $to   = ymd((string) $this->request->getGet('to')) ?? $from;
        $dash = new \App\Services\Dashboard\DashboardService($this->db, $this->authz);

        return $this->render('reports/digest', [
            'pageTitle' => 'Digest ' . dmy($from) . ($from !== $to ? ' – ' . dmy($to) : ''), 'breadcrumbs' => [['Reports', 'reports'], ['Digest']],
            'from' => $from, 'to' => $to,
            'k' => $dash->financeKpis($from, $to),
            'issued' => $this->db->table('billing_documents')->select('doc_type, document_number, bill_to_name, grand_total')->where('status', 'finalized')
                ->whereIn('doc_type', ['tax_invoice', 'bill_of_supply', 'cash_memo', 'credit_note', 'debit_note'])->where('issue_date >=', $from)->where('issue_date <=', $to)->orderBy('id')->get()->getResultArray(),
            'byMode' => $this->db->query("SELECT mode, SUM(CASE WHEN receipt_type = 'refund' THEN -amount ELSE amount END) amt, COUNT(*) n FROM payment_receipts WHERE status = 'active' AND receipt_date BETWEEN ? AND ? GROUP BY mode ORDER BY amt DESC", [$from, $to])->getResultArray(),
            'overdue' => $this->db->table('billing_documents d')->select('d.document_number, d.due_date, d.balance_due, c.name')->join('contacts c', 'c.id = d.contact_id')
                ->where('d.status', 'finalized')->where('d.balance_due >', 0)->where('d.due_date <', date('Y-m-d'))->orderBy('d.balance_due', 'DESC')->limit(10)->get()->getResultArray(),
            'pending' => [
                'Drafts awaiting finalization' => $this->db->table('billing_documents')->where('status', 'draft')->countAllResults(),
                'Expenses awaiting approval'   => $this->db->table('expenses')->where(['approval_status' => 'pending', 'deleted_at' => null])->countAllResults(),
                'Unmatched bank lines'         => $this->db->table('bank_import_lines')->where('match_status', 'unmatched')->countAllResults(),
                'Receipts in personal accounts to review' => $this->db->table('payment_receipts')->where(['requires_review' => 1, 'status' => 'active', 'reconciliation_status' => 'unmatched'])->countAllResults(),
            ],
        ]);
    }
}
