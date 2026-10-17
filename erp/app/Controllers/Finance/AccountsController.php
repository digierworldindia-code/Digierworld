<?php

namespace App\Controllers\Finance;

use App\Controllers\BaseController;
use App\Libraries\Dec;

/** Bank / cash / UPI accounts with book balances and account statements. */
class AccountsController extends BaseController
{
    public function index()
    {
        $rows = $this->db->table('bank_cash_accounts a')
            ->select('a.id, a.code, a.display_name, a.account_kind, a.ownership, a.bank_name, a.account_last4, a.is_active, a.requires_review, a.ledger_account_id')
            ->orderBy('a.is_active', 'DESC')->orderBy('a.display_priority')->orderBy('a.display_name')->get()->getResultArray();
        $ledger = service('ledger');
        $total  = '0';
        foreach ($rows as &$r) {
            $r['balance']   = $ledger->bankBalance((int) $r['id']);
            $r['unmatched'] = $this->db->table('bank_import_lines')->where(['bank_cash_account_id' => $r['id'], 'match_status' => 'unmatched'])->countAllResults();
            $r['last_import'] = $this->db->table('bank_imports')->select('MAX(statement_to) d', false)->where('bank_cash_account_id', $r['id'])->get()->getRow()->d ?? null;
            if ((int) $r['is_active'] === 1) {
                $total = Dec::add($total, $r['balance']);
            }
        }
        unset($r);

        return $this->render('finance/accounts/index', [
            'pageTitle' => 'Bank & cash accounts', 'breadcrumbs' => [['Finance'], ['Bank & cash accounts']],
            'rows' => $rows, 'total' => $total,
        ]);
    }

    /** Account statement: opening balance, movements with running balance, closing balance. */
    public function show($id)
    {
        $acc  = $this->find($this->idOr404($id));
        [$from, $to] = $this->range();
        $data = $this->statement($acc, $from, $to);

        return $this->render('finance/accounts/show', $data + [
            'pageTitle' => $acc['display_name'], 'breadcrumbs' => [['Finance'], ['Bank & cash accounts', 'finance/accounts'], [$acc['display_name']]],
            'acc' => $acc, 'from' => $from, 'to' => $to,
        ]);
    }

    public function export($id)
    {
        $this->authorize('reports.export');
        $acc = $this->find($this->idOr404($id));
        [$from, $to] = $this->range();
        $st  = $this->statement($acc, $from, $to);
        $rows = [['entry_date' => $from, 'entry_no' => '', 'narration' => 'Opening balance', 'reference' => '', 'debit' => null, 'credit' => null, 'running' => $st['opening']]];
        foreach ($st['lines'] as $l) {
            $rows[] = ['entry_date' => $l['entry_date'], 'entry_no' => $l['entry_no'], 'narration' => $l['narration'], 'reference' => $l['reference'], 'debit' => $l['debit'], 'credit' => $l['credit'], 'running' => $l['running']];
        }
        $cols = [
            ['key' => 'entry_date', 'label' => 'Date', 'type' => 'date'], ['key' => 'entry_no', 'label' => 'Voucher'], ['key' => 'narration', 'label' => 'Particulars', 'width' => 50],
            ['key' => 'reference', 'label' => 'Reference'], ['key' => 'debit', 'label' => 'Money in', 'type' => 'money'], ['key' => 'credit', 'label' => 'Money out', 'type' => 'money'],
            ['key' => 'running', 'label' => 'Balance', 'type' => 'money'],
        ];

        return service('exports')->download('account_statement', (string) $this->request->getGet('format'), 'Statement_' . $acc['code'], [['title' => 'Statement', 'columns' => $cols, 'rows' => $rows]],
            ['account' => $acc['display_name'], 'from' => $from, 'to' => $to], ['Report' => 'Account statement — ' . $acc['display_name']]);
    }

    /** @return array{0: string, 1: string} */
    private function range(): array
    {
        $fy   = fy_for_date(date('Y-m-d'));
        $from = ymd((string) $this->request->getGet('from')) ?? $fy['start_date'];
        $to   = ymd((string) $this->request->getGet('to')) ?? date('Y-m-d');

        return $from > $to ? [$to, $from] : [$from, $to];
    }

    private function find(int $id): array
    {
        $acc = $this->db->table('bank_cash_accounts')->select('id, code, display_name, account_kind, ownership, bank_name, account_last4, ifsc, ledger_account_id, opening_balance, opening_date, is_active, requires_review, classification_note')
            ->where('id', $id)->get()->getRowArray();
        if ($acc === null) {
            $this->notFound();
        }

        return $acc;
    }

    /** @return array{opening: string, closing: string, inflow: string, outflow: string, lines: list<array<string, mixed>>} */
    private function statement(array $acc, string $from, string $to): array
    {
        $ledgerId = (int) $acc['ledger_account_id'];
        $opening  = service('ledger')->balance($ledgerId, date('Y-m-d', strtotime($from . ' -1 day')));
        $lines    = $this->db->table('journal_lines jl')
            ->select('jl.id, jl.debit, jl.credit, jl.memo, je.id AS entry_id, je.entry_no, je.entry_date, je.narration, je.reference, je.source_type, je.source_id, c.name AS contact_name')
            ->join('journal_entries je', 'je.id = jl.journal_entry_id')->join('contacts c', 'c.id = jl.contact_id', 'left')
            ->where('jl.ledger_account_id', $ledgerId)->where('je.entry_date >=', $from)->where('je.entry_date <=', $to)
            ->orderBy('je.entry_date')->orderBy('je.id')->orderBy('jl.id')->get()->getResultArray();
        $run = $opening;
        $in  = '0';
        $out = '0';
        foreach ($lines as &$l) {
            $run = Dec::add($run, Dec::sub($l['debit'], $l['credit']));
            $l['running'] = $run;
            $in  = Dec::add($in, $l['debit']);
            $out = Dec::add($out, $l['credit']);
        }
        unset($l);

        return ['opening' => $opening, 'closing' => $run, 'inflow' => $in, 'outflow' => $out, 'lines' => $lines];
    }
}
