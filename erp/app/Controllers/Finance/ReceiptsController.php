<?php

namespace App\Controllers\Finance;

use App\Controllers\BaseController;
use App\Exceptions\BusinessRuleException;
use App\Services\Finance\ReceiptService;

/** Receipt vouchers (customer payments), advances and refunds. */
class ReceiptsController extends BaseController
{
    private const MODE_LABELS = [
        'cash' => 'Cash', 'upi' => 'UPI', 'bank_transfer' => 'Bank transfer', 'neft' => 'NEFT', 'rtgs' => 'RTGS', 'imps' => 'IMPS',
        'cheque' => 'Cheque', 'card' => 'Card', 'other' => 'Other',
    ];

    /** @return array<string, mixed> */
    private function filters(): array
    {
        $g = $this->request->getGet();

        return [
            'q'         => trim((string) ($g['q'] ?? '')),
            'type'      => in_array($g['type'] ?? '', ['receipt', 'refund'], true) ? $g['type'] : '',
            'mode'      => isset(self::MODE_LABELS[$g['mode'] ?? '']) ? $g['mode'] : '',
            'account'   => (int) ($g['account'] ?? 0),
            'status'    => in_array($g['status'] ?? '', ['active', 'void'], true) ? $g['status'] : '',
            'unapplied' => ! empty($g['unapplied']),
            'review'    => ! empty($g['review']),
            'contact'   => (int) ($g['contact'] ?? 0),
            'from'      => ymd((string) ($g['from'] ?? '')),
            'to'        => ymd((string) ($g['to'] ?? '')),
        ];
    }

    private function query(array $f, ?string $select = null)
    {
        $b = $this->db->table('payment_receipts r')
            ->select($select ?? 'r.*, c.name AS contact_name, c.contact_code, a.display_name AS account_name, a.account_kind, a.ownership', $select === null)
            ->join('contacts c', 'c.id = r.contact_id')
            ->join('bank_cash_accounts a', 'a.id = r.bank_cash_account_id');
        if ($f['q'] !== '') {
            $b->groupStart()->like('r.receipt_number', $f['q'])->orLike('r.reference_no', $f['q'])->orLike('c.name', $f['q'])->groupEnd();
        }
        foreach (['type' => 'r.receipt_type', 'mode' => 'r.mode', 'status' => 'r.status'] as $k => $col) {
            if ($f[$k] !== '') {
                $b->where($col, $f[$k]);
            }
        }
        if ($f['account'] > 0) {
            $b->where('r.bank_cash_account_id', $f['account']);
        }
        if ($f['contact'] > 0) {
            $b->where('r.contact_id', $f['contact']);
        }
        if ($f['unapplied']) {
            $b->where('r.status', 'active')->where('r.receipt_type', 'receipt')->where('r.unapplied_amount >', 0);
        }
        if ($f['review']) {
            $b->where('r.requires_review', 1);
        }
        if ($f['from']) {
            $b->where('r.receipt_date >=', $f['from']);
        }
        if ($f['to']) {
            $b->where('r.receipt_date <=', $f['to']);
        }

        return $b;
    }

    public function index()
    {
        $f = $this->filters();
        [$page, $perPage, $offset] = $this->paging(25);
        $total = $this->query($f)->countAllResults();
        $rows  = $this->query($f)->orderBy('r.receipt_date', 'DESC')->orderBy('r.id', 'DESC')->limit($perPage, $offset)->get()->getResultArray();
        $sums  = $this->query($f, "SUM(CASE WHEN r.status = 'active' AND r.receipt_type = 'receipt' THEN r.amount ELSE 0 END) received, SUM(CASE WHEN r.status = 'active' AND r.receipt_type = 'refund' THEN r.amount ELSE 0 END) refunded, SUM(CASE WHEN r.status = 'active' AND r.receipt_type = 'receipt' THEN r.unapplied_amount ELSE 0 END) unapplied")
            ->get()->getRowArray();

        return $this->render('finance/receipts/index', [
            'pageTitle' => 'Payments & receipts', 'breadcrumbs' => [['Finance'], ['Payments & receipts']],
            'rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage, 'f' => $f, 'sums' => $sums,
            'accounts' => $this->accounts(), 'modes' => self::MODE_LABELS,
        ]);
    }

    public function export()
    {
        $this->authorize('finance.receipt.view');
        $f    = $this->filters();
        $rows = $this->query($f)->orderBy('r.receipt_date')->orderBy('r.id')->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['mode_label'] = self::MODE_LABELS[$r['mode']] ?? $r['mode'];
        }
        unset($r);
        $cols = [
            ['key' => 'receipt_number', 'label' => 'Voucher no.'], ['key' => 'receipt_type', 'label' => 'Type'], ['key' => 'receipt_date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'contact_name', 'label' => 'Customer', 'width' => 30], ['key' => 'mode_label', 'label' => 'Mode'], ['key' => 'account_name', 'label' => 'Account', 'width' => 24],
            ['key' => 'reference_no', 'label' => 'Reference / UTR'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'],
            ['key' => 'allocated_amount', 'label' => 'Applied', 'type' => 'money'], ['key' => 'unapplied_amount', 'label' => 'Unapplied', 'type' => 'money'],
            ['key' => 'status', 'label' => 'Status'], ['key' => 'reconciliation_status', 'label' => 'Bank recon'],
        ];

        return service('exports')->download('receipts_register', (string) $this->request->getGet('format'), 'Receipts_register', [['title' => 'Receipts', 'columns' => $cols, 'rows' => $rows]], $f, ['Report' => 'Receipts & refunds register']);
    }

    /** @return list<array<string, mixed>> */
    private function accounts(): array
    {
        return $this->db->table('bank_cash_accounts')->select('id, display_name, account_kind, ownership, requires_review')->where('is_active', 1)->orderBy('display_priority')->orderBy('display_name')->get()->getResultArray();
    }

    public function new()
    {
        $contact = null;
        if ($cid = (int) $this->request->getGet('contact')) {
            $contact = $this->db->table('contacts')->select('id, name, contact_code, gstin')->where('id', $cid)->where('deleted_at', null)->get()->getRowArray();
        }
        if ($contact === null && ($did = (int) $this->request->getGet('document'))) {
            $contact = $this->db->table('billing_documents d')->select('c.id, c.name, c.contact_code, c.gstin')->join('contacts c', 'c.id = d.contact_id')->where('d.id', $did)->get()->getRowArray();
        }

        return $this->render('finance/receipts/form', [
            'pageTitle' => 'Record payment', 'breadcrumbs' => [['Finance'], ['Payments & receipts', 'finance/receipts'], ['Record payment']],
            'contact' => $contact, 'focusDocument' => (int) $this->request->getGet('document'),
            'accounts' => $this->accounts(), 'modes' => self::MODE_LABELS,
            'canAllocate' => $this->can('finance.receipt.allocate'),
        ]);
    }

    /** JSON: open (unpaid) documents and unapplied advances of a customer. */
    public function openDocuments()
    {
        $cid = (int) $this->request->getGet('contact');
        $docs = (new ReceiptService($this->db, service('ledger'), service('numbers')))->openDocuments($cid);
        foreach ($docs as &$d) {
            $d['label'] = doc_type_label($d['doc_type']);
            $d['overdue'] = $d['due_date'] !== null && $d['due_date'] < date('Y-m-d');
        }
        unset($d);
        $adv = $this->db->table('payment_receipts')->select('id, receipt_number, receipt_date, unapplied_amount')
            ->where(['contact_id' => $cid, 'status' => 'active', 'receipt_type' => 'receipt'])->where('unapplied_amount >', 0)->orderBy('receipt_date')->get()->getResultArray();

        return $this->json(['documents' => $docs, 'advances' => $adv]);
    }

    public function create()
    {
        $in     = $this->request->getPost();
        $allocs = [];
        if ($this->can('finance.receipt.allocate')) {
            foreach ((array) ($in['alloc'] ?? []) as $docId => $amt) {
                if (trim((string) $amt) !== '') {
                    $allocs[] = ['document_id' => (int) $docId, 'amount' => (string) $amt];
                }
            }
        }

        try {
            $id = service('receipts')->create([
                'contact_id' => (int) ($in['contact_id'] ?? 0), 'receipt_date' => (string) ($in['receipt_date'] ?? ''), 'amount' => (string) ($in['amount'] ?? ''),
                'mode' => (string) ($in['mode'] ?? ''), 'bank_cash_account_id' => (int) ($in['bank_cash_account_id'] ?? 0),
                'reference_no' => $in['reference_no'] ?? null, 'cheque_date' => $in['cheque_date'] ?? null, 'notes' => $in['notes'] ?? null,
                'allocations' => $allocs, 'allow_duplicate' => ! empty($in['allow_duplicate']),
            ]);
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }
        $r = $this->db->table('payment_receipts')->where('id', $id)->get()->getRowArray();
        $msg = 'Receipt ' . $r['receipt_number'] . ' recorded for ' . inr($r['amount']) . '.';
        if ((float) $r['unapplied_amount'] > 0) {
            $msg .= ' ' . inr($r['unapplied_amount']) . ' is kept as an advance.';
        }

        return redirect()->to(site_url('finance/receipts/' . $id))->with('success', $msg);
    }

    private function load(int $id): array
    {
        $r = $this->db->table('payment_receipts r')
            ->select('r.*, c.name AS contact_name, c.contact_code, c.gstin AS contact_gstin, a.display_name AS account_name, a.account_kind, a.ownership, p.full_name AS creator')
            ->join('contacts c', 'c.id = r.contact_id')->join('bank_cash_accounts a', 'a.id = r.bank_cash_account_id')
            ->join('user_profiles p', 'p.user_id = r.created_by', 'left')->where('r.id', $id)->get()->getRowArray();
        if ($r === null) {
            $this->notFound();
        }

        return $r;
    }

    public function show($id)
    {
        $r = $this->load($this->idOr404($id));

        return $this->render('finance/receipts/show', [
            'pageTitle'   => ($r['receipt_type'] === 'refund' ? 'Refund ' : 'Receipt ') . $r['receipt_number'],
            'breadcrumbs' => [['Finance'], ['Payments & receipts', 'finance/receipts'], [$r['receipt_number']]],
            'r'           => $r,
            'modes'       => self::MODE_LABELS,
            'allocations' => $this->db->table('payment_allocations a')->select('a.*, d.doc_type, d.document_number, d.issue_date, d.grand_total, d.balance_due')
                ->join('billing_documents d', 'd.id = a.document_id')->where('a.receipt_id', $r['id'])->orderBy('a.id')->get()->getResultArray(),
            'refunds'     => $this->db->table('payment_receipts')->select('id, receipt_number, receipt_date, amount, status')->where('refund_of_receipt_id', $r['id'])->get()->getResultArray(),
            'source'      => $r['refund_of_receipt_id'] ? $this->db->table('payment_receipts')->select('id, receipt_number')->where('id', $r['refund_of_receipt_id'])->get()->getRowArray() : null,
            'journal'     => $r['journal_entry_id'] ? $this->db->table('journal_entries')->select('id, entry_no, entry_date')->where('id', $r['journal_entry_id'])->get()->getRowArray() : null,
            'history'     => $this->db->table('audit_logs a')->select('a.occurred_at, a.action, a.summary, p.full_name')->join('user_profiles p', 'p.user_id = a.user_id', 'left')
                ->where(['a.entity_type' => 'payment_receipt', 'a.entity_id' => $r['id']])->orderBy('a.id')->get()->getResultArray(),
            'openDocs'    => $r['status'] === 'active' && (float) $r['unapplied_amount'] > 0 && $this->can('finance.receipt.allocate')
                ? (new ReceiptService($this->db, service('ledger'), service('numbers')))->openDocuments((int) $r['contact_id']) : [],
            'accounts'    => $this->can('finance.receipt.refund') ? $this->accounts() : [],
        ]);
    }

    public function pdf($id)
    {
        $r = $this->load($this->idOr404($id));
        $allocs = $this->db->table('payment_allocations a')->select('a.amount, d.doc_type, d.document_number, d.issue_date')
            ->join('billing_documents d', 'd.id = a.document_id')->where(['a.receipt_id' => $r['id'], 'a.reversed_at' => null])->get()->getResultArray();
        $contact = $this->db->table('contacts')->select('name, legal_name, gstin, phone, state_code')->where('id', $r['contact_id'])->get()->getRowArray();
        $logo    = $this->db->table('branding_assets')->where(['asset_type' => 'logo', 'is_active' => 1])->get()->getRowArray();
        $bytes   = service('pdf')->renderView('pdf/receipt', [
            'r'        => $r,
            'allocs'   => $allocs,
            'contact'  => $contact,
            'supplier' => (new \App\Services\Billing\SnapshotBuilder($this->db))->supplier(),
            'logo'     => $logo ? service('files')->dataUri((int) $logo['stored_file_id']) : null,
            'modes'    => self::MODE_LABELS,
            'words'    => \App\Libraries\AmountInWords::inr($r['amount']),
        ], 'A5', 'landscape');
        service('audit')->log('receipt.pdf_viewed', 'payment_receipt', (int) $r['id'], null, null, 'Voucher PDF ' . $r['receipt_number']);
        $name = preg_replace('/[^A-Za-z0-9_\-]+/', '_', ($r['receipt_type'] === 'refund' ? 'Refund_' : 'Receipt_') . $r['receipt_number']) . '.pdf';

        return $this->response->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', ($this->request->getGet('download') ? 'attachment' : 'inline') . '; filename="' . $name . '"')
            ->setHeader('Cache-Control', 'private, no-store')->setBody($bytes);
    }

    public function apply($id)
    {
        $r      = $this->load($this->idOr404($id));
        $allocs = [];
        foreach ((array) $this->request->getPost('alloc') as $docId => $amt) {
            if (trim((string) $amt) !== '') {
                $allocs[] = ['document_id' => (int) $docId, 'amount' => (string) $amt];
            }
        }

        try {
            service('receipts')->applyAdvance((int) $r['id'], $allocs);
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('finance/receipts/' . $r['id']))->with('success', 'Advance applied to the selected invoices.');
    }

    public function void($id)
    {
        $r = $this->load($this->idOr404($id));

        try {
            service('receipts')->void((int) $r['id'], (string) $this->request->getPost('reason'));
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('finance/receipts/' . $r['id']))->with('success', 'Receipt voided. The accounting entry was reversed and the invoices re-opened.');
    }

    public function cheque($id)
    {
        $r      = $this->load($this->idOr404($id));
        $status = (string) $this->request->getPost('status');
        if ($status === 'bounced') {
            $this->authorize('finance.receipt.void');
        }

        try {
            service('receipts')->chequeStatus((int) $r['id'], $status, trim((string) $this->request->getPost('note')));
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('finance/receipts/' . $r['id']))->with('success', $status === 'bounced' ? 'Cheque marked bounced; the receipt was voided and invoices re-opened.' : 'Cheque marked cleared.');
    }

    public function newRefund()
    {
        $cid     = (int) $this->request->getGet('contact');
        $contact = $cid ? $this->db->table('contacts')->select('id, name, contact_code')->where('id', $cid)->get()->getRowArray() : null;
        $credit  = '0.00';
        $advances = [];
        if ($contact !== null) {
            $credit   = \App\Libraries\Dec::max(\App\Libraries\Dec::neg(service('ledger')->balance(service('ledger')->account('receivable'), null, $cid)), '0');
            $advances = $this->db->table('payment_receipts')->select('id, receipt_number, receipt_date, unapplied_amount')
                ->where(['contact_id' => $cid, 'status' => 'active', 'receipt_type' => 'receipt'])->where('unapplied_amount >', 0)->get()->getResultArray();
        }

        return $this->render('finance/receipts/refund', [
            'pageTitle' => 'Refund to customer', 'breadcrumbs' => [['Finance'], ['Payments & receipts', 'finance/receipts'], ['Refund']],
            'contact' => $contact, 'credit' => $credit, 'advances' => $advances, 'accounts' => $this->accounts(), 'modes' => self::MODE_LABELS,
            'fromReceipt' => (int) $this->request->getGet('receipt'),
            'candidates'  => $contact === null ? $this->db->query(
                "SELECT c.id, c.name FROM contacts c WHERE c.id IN (SELECT contact_id FROM payment_receipts WHERE status = 'active' AND receipt_type = 'receipt' AND unapplied_amount > 0)
                 OR c.id IN (SELECT l.contact_id FROM journal_lines l WHERE l.ledger_account_id = ? AND l.contact_id IS NOT NULL GROUP BY l.contact_id HAVING SUM(l.debit) - SUM(l.credit) < 0)
                 ORDER BY c.name",
                [service('ledger')->account('receivable')]
            )->getResultArray() : [],
        ]);
    }

    public function createRefund()
    {
        $in   = $this->request->getPost();
        $from = (int) ($in['from_receipt_id'] ?? 0);
        $mode = (string) ($in['mode'] ?? '');
        if (! isset(self::MODE_LABELS[$mode])) {
            return redirect()->back()->withInput()->with('error', 'Choose the refund mode.');
        }

        try {
            $id = service('receipts')->refund((int) ($in['contact_id'] ?? 0), str_replace(',', '', (string) ($in['amount'] ?? '0')), ymd((string) ($in['refund_date'] ?? '')) ?? date('Y-m-d'),
                (int) ($in['bank_cash_account_id'] ?? 0), $mode, $from > 0 ? $from : null, trim((string) ($in['notes'] ?? '')));
        } catch (BusinessRuleException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('finance/receipts/' . $id))->with('success', 'Refund recorded.');
    }
}
