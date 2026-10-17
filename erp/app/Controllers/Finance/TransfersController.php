<?php

namespace App\Controllers\Finance;

use App\Controllers\BaseController;
use App\Exceptions\BusinessRuleException;

/** Cash → bank deposits and other internal transfers. Never income, never GST. */
class TransfersController extends BaseController
{
    public const KINDS = [
        'cash_deposit' => 'Cash deposit', 'cash_withdrawal' => 'Cash withdrawal', 'bank_transfer' => 'Bank to bank',
        'upi_settlement' => 'UPI settlement', 'owner_clearing_settlement' => 'Owner account settlement', 'other' => 'Other',
    ];

    private function query(array $f, ?string $select = null)
    {
        $b = $this->db->table('money_transfers t')
            ->select($select ?? 't.*, fa.display_name AS from_name, ta.display_name AS to_name, p.full_name AS creator', $select === null)
            ->join('bank_cash_accounts fa', 'fa.id = t.from_account_id')->join('bank_cash_accounts ta', 'ta.id = t.to_account_id')
            ->join('user_profiles p', 'p.user_id = t.created_by', 'left');
        if ($f['kind'] !== '') {
            $b->where('t.transfer_kind', $f['kind']);
        }
        if ($f['status'] !== '') {
            $b->where('t.status', $f['status']);
        }
        if ($f['account'] > 0) {
            $b->groupStart()->where('t.from_account_id', $f['account'])->orWhere('t.to_account_id', $f['account'])->groupEnd();
        }
        if ($f['from']) {
            $b->where('t.transfer_date >=', $f['from']);
        }
        if ($f['to']) {
            $b->where('t.transfer_date <=', $f['to']);
        }
        if ($f['q'] !== '') {
            $b->groupStart()->like('t.transfer_number', $f['q'])->orLike('t.reference_no', $f['q'])->orLike('t.notes', $f['q'])->groupEnd();
        }

        return $b;
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        $g = $this->request->getGet();

        return [
            'q' => trim((string) ($g['q'] ?? '')), 'kind' => isset(self::KINDS[$g['kind'] ?? '']) ? $g['kind'] : '',
            'status' => in_array($g['status'] ?? '', ['active', 'void'], true) ? $g['status'] : '', 'account' => (int) ($g['account'] ?? 0),
            'from' => ymd((string) ($g['from'] ?? '')), 'to' => ymd((string) ($g['to'] ?? '')),
        ];
    }

    public function index()
    {
        $f = $this->filters();
        [$page, $perPage, $offset] = $this->paging(25);

        return $this->render('finance/transfers/index', [
            'pageTitle' => 'Deposits & transfers', 'breadcrumbs' => [['Finance'], ['Deposits & transfers']],
            'rows' => $this->query($f)->orderBy('t.transfer_date', 'DESC')->orderBy('t.id', 'DESC')->limit($perPage, $offset)->get()->getResultArray(),
            'total' => $this->query($f)->countAllResults(), 'page' => $page, 'perPage' => $perPage, 'f' => $f,
            'sum' => $this->query($f, "COALESCE(SUM(CASE WHEN t.status = 'active' THEN t.amount ELSE 0 END),0) s")->get()->getRow()->s,
            'accounts' => $this->accounts(), 'kinds' => self::KINDS,
        ]);
    }

    public function export()
    {
        $this->authorize('finance.transfer.view');
        $f    = $this->filters();
        $rows = $this->query($f)->orderBy('t.transfer_date')->orderBy('t.id')->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['kind_label'] = self::KINDS[$r['transfer_kind']] ?? $r['transfer_kind'];
        }
        unset($r);
        $cols = [
            ['key' => 'transfer_number', 'label' => 'Number'], ['key' => 'transfer_date', 'label' => 'Date', 'type' => 'date'], ['key' => 'kind_label', 'label' => 'Kind'],
            ['key' => 'from_name', 'label' => 'From', 'width' => 26], ['key' => 'to_name', 'label' => 'To', 'width' => 26], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'],
            ['key' => 'reference_no', 'label' => 'Reference'], ['key' => 'status', 'label' => 'Status'], ['key' => 'notes', 'label' => 'Notes', 'width' => 30],
        ];

        return service('exports')->download('transfers_register', (string) $this->request->getGet('format'), 'Transfers_register', [['title' => 'Transfers', 'columns' => $cols, 'rows' => $rows]], $f, ['Report' => 'Deposits & transfers register (not income)']);
    }

    /** @return list<array<string, mixed>> */
    private function accounts(): array
    {
        $rows = $this->db->table('bank_cash_accounts')->select('id, display_name, account_kind, ownership')->where('is_active', 1)->orderBy('display_priority')->orderBy('display_name')->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['balance'] = service('ledger')->bankBalance((int) $r['id']);
        }

        return $rows;
    }

    public function new()
    {
        $accounts = $this->accounts();
        $from     = (int) $this->request->getGet('from');
        $to       = 0;
        if ($this->request->getGet('preset') === 'deposit') {
            foreach ($accounts as $a) {
                if ($a['account_kind'] === 'cash' && $from === 0) {
                    $from = (int) $a['id'];
                }
                if ($a['account_kind'] === 'bank' && $a['ownership'] === 'company' && $to === 0) {
                    $to = (int) $a['id'];
                }
            }
        }

        return $this->render('finance/transfers/form', [
            'pageTitle' => 'New transfer', 'breadcrumbs' => [['Finance'], ['Deposits & transfers', 'finance/transfers'], ['New']],
            'accounts' => $accounts, 'fromId' => $from, 'toId' => $to,
        ]);
    }

    public function create()
    {
        $in = $this->request->getPost();

        try {
            $id = service('transfers')->create([
                'from_account_id' => (int) ($in['from_account_id'] ?? 0), 'to_account_id' => (int) ($in['to_account_id'] ?? 0),
                'amount' => (string) ($in['amount'] ?? ''), 'transfer_date' => (string) ($in['transfer_date'] ?? ''),
                'reference_no' => $in['reference_no'] ?? null, 'notes' => $in['notes'] ?? null,
                'allow_negative' => ! empty($in['allow_negative']) && $this->can('finance.transfer.void'),
            ]);
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to(site_url('finance/transfers/' . $id))->with('success', 'Transfer recorded. It moves money between your own accounts and is not income.');
    }

    public function show($id)
    {
        $t = $this->query(['q' => '', 'kind' => '', 'status' => '', 'account' => 0, 'from' => null, 'to' => null])->where('t.id', $this->idOr404($id))->get()->getRowArray();
        if ($t === null) {
            $this->notFound();
        }

        return $this->render('finance/transfers/show', [
            'pageTitle' => 'Transfer ' . $t['transfer_number'], 'breadcrumbs' => [['Finance'], ['Deposits & transfers', 'finance/transfers'], [$t['transfer_number']]],
            't' => $t, 'kinds' => self::KINDS,
            'journal' => $t['journal_entry_id'] ? $this->db->table('journal_entries')->select('id, entry_no')->where('id', $t['journal_entry_id'])->get()->getRowArray() : null,
        ]);
    }

    public function void($id)
    {
        $id = $this->idOr404($id);
        $reason = trim((string) $this->request->getPost('reason'));
        if (mb_strlen($reason) < 5) {
            return redirect()->back()->with('error', 'Give a reason for voiding.');
        }

        try {
            service('transfers')->void($id, $reason);
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('finance/transfers/' . $id))->with('success', 'Transfer voided and its entry reversed.');
    }
}
