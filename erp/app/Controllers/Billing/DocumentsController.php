<?php

namespace App\Controllers\Billing;

use App\Controllers\BaseController;
use App\Exceptions\BusinessRuleException;
use App\Services\Reports\ExportService;
use Config\Billing;

/** Create Document / Invoices / Cash Memos / Estimates / Credit & Debit notes. */
class DocumentsController extends BaseController
{
    private function access()
    {
        return service('billingAccess');
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        $g = $this->request->getGet();

        return [
            'type'    => (string) ($g['type'] ?? ''),
            'status'  => (string) ($g['status'] ?? ''),
            'payment' => (string) ($g['payment'] ?? ''),
            'gst'     => (string) ($g['gst'] ?? ''),
            'q'       => trim((string) ($g['q'] ?? '')),
            'from'    => ymd((string) ($g['from'] ?? '')),
            'to'      => ymd((string) ($g['to'] ?? '')),
            'contact' => (int) ($g['contact'] ?? 0),
            'service' => (int) ($g['service'] ?? 0),
        ];
    }

    private function query(array $f, ?string $select = null)
    {
        $types = $f['type'] === 'estimate' ? ['estimate', 'quotation', 'proforma'] : ($f['type'] === 'credit_note' ? ['credit_note', 'debit_note'] : ($f['type'] !== '' ? [$f['type']] : []));
        $b = $this->db->table('billing_documents d')
            ->join('user_profiles p', 'p.user_id = d.created_by', 'left')
            ->select($select ?? 'd.id, d.doc_type, d.status, d.draft_ref, d.document_number, d.issue_date, d.due_date, d.bill_to_name, d.bill_to_gstin, d.gst_type, d.place_of_supply_state_code, d.taxable_total, d.tax_total, d.grand_total, d.balance_due, d.payment_status, d.tax_review_status, d.created_by, d.contact_id, p.full_name AS creator', $select === null);
        $this->access()->applyScope($b, 'd');
        if ($types !== []) {
            $b->whereIn('d.doc_type', $types);
        }
        if (in_array($f['status'], ['draft', 'finalized', 'cancelled', 'converted'], true)) {
            $b->where('d.status', $f['status']);
        }
        if (in_array($f['payment'], ['unpaid', 'partial', 'paid', 'overpaid'], true)) {
            $b->where('d.payment_status', $f['payment']);
        } elseif ($f['payment'] === 'overdue') {
            $b->where('d.status', 'finalized')->where('d.balance_due >', 0)->where('d.due_date <', date('Y-m-d'));
        }
        if (in_array($f['gst'], ['cgst_sgst', 'cgst_utgst', 'igst', 'none'], true)) {
            $b->where('d.gst_type', $f['gst']);
        }
        if ($f['q'] !== '') {
            $b->groupStart()->like('d.document_number', $f['q'])->orLike('d.draft_ref', $f['q'])->orLike('d.bill_to_name', $f['q'])->orLike('d.bill_to_gstin', strtoupper($f['q']))->groupEnd();
        }
        if ($f['from']) {
            $b->where('d.issue_date >=', $f['from']);
        }
        if ($f['to']) {
            $b->where('d.issue_date <=', $f['to']);
        }
        if ($f['contact'] > 0) {
            $b->where('d.contact_id', $f['contact']);
        }
        if ($f['service'] > 0) {
            $b->where('d.id IN (SELECT document_id FROM billing_line_items WHERE service_id = ' . (int) $f['service'] . ')', null, false);
        }

        return $b;
    }

    public function index()
    {
        $f = $this->filters();
        [$page, $perPage, $offset] = $this->paging(25);
        $b     = $this->query($f);
        $total = (clone $b)->countAllResults();
        $sums  = $this->can(['dashboard.finance_kpis', 'reports.billing.view'])
            ? $this->query($f, 'SUM(CASE WHEN d.status = \'finalized\' THEN d.grand_total ELSE 0 END) total_value, SUM(CASE WHEN d.status = \'finalized\' THEN d.balance_due ELSE 0 END) total_due')->get()->getRowArray()
            : null;
        $rows  = $b->orderBy('d.issue_date', 'DESC')->orderBy('d.id', 'DESC')->limit($perPage, $offset)->get()->getResultArray();
        $label = match ($f['type']) {
            'tax_invoice' => 'Tax invoices', 'cash_memo' => 'Cash memos', 'estimate' => 'Estimates, quotations & proforma',
            'credit_note' => 'Credit & debit notes', 'bill_of_supply' => 'Bills of supply', default => 'All billing documents',
        };

        return $this->render('billing/index', [
            'pageTitle' => $label, 'breadcrumbs' => [['Billing'], [$label]],
            'rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage, 'f' => $f, 'sums' => $sums,
            'services' => $this->db->table('services')->select('id, name')->where('is_active', 1)->orderBy('name')->get()->getResultArray(),
            'canExport' => $this->can(['billing.invoice.export', 'billing.cash_memo.export', 'billing.export_all']),
        ]);
    }

    public function export()
    {
        $f = $this->filters();
        // Users without export_all only export the document types they can export.
        if (! $this->can('billing.export_all')) {
            $allowed = array_filter(['tax_invoice' => 'billing.invoice.export', 'bill_of_supply' => 'billing.invoice.export', 'cash_memo' => 'billing.cash_memo.export'], fn ($p) => $this->can($p));
            if ($f['type'] === '' || ! isset($allowed[$f['type']])) {
                $f['type'] = (string) array_key_first($allowed);
            }
        }
        $rows = $this->query($f)->orderBy('d.issue_date')->orderBy('d.id')->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['type_label'] = doc_type_label($r['doc_type']);
            $r['number']     = $r['document_number'] ?? $r['draft_ref'];
            $r['gst_label']  = gst_type_label($r['gst_type']);
        }
        unset($r);
        $cols = [
            ['key' => 'type_label', 'label' => 'Type'], ['key' => 'number', 'label' => 'Number'], ['key' => 'issue_date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'status', 'label' => 'Status'], ['key' => 'bill_to_name', 'label' => 'Customer', 'width' => 30], ['key' => 'bill_to_gstin', 'label' => 'GSTIN'],
            ['key' => 'place_of_supply_state_code', 'label' => 'PoS'], ['key' => 'gst_label', 'label' => 'GST type', 'width' => 26],
            ['key' => 'taxable_total', 'label' => 'Taxable', 'type' => 'money'], ['key' => 'tax_total', 'label' => 'Tax', 'type' => 'money'],
            ['key' => 'grand_total', 'label' => 'Total', 'type' => 'money'], ['key' => 'balance_due', 'label' => 'Balance', 'type' => 'money'], ['key' => 'payment_status', 'label' => 'Payment'],
        ];

        return (new ExportService($this->db))->download('billing_documents', (string) $this->request->getGet('format'), 'billing_documents',
            [['title' => 'Documents', 'columns' => $cols, 'rows' => $rows, 'totals' => ['taxable_total', 'tax_total', 'grand_total', 'balance_due']]], $f, ['Report' => 'Billing documents']);
    }

    // ---- create / edit -----------------------------------------------

    public function new()
    {
        $type = (string) ($this->request->getGet('type') ?: 'tax_invoice');
        if (! isset(config(Billing::class)->docTypes[$type]) || ! $this->access()->can('create_draft', $type)) {
            $type = (string) (array_values(array_filter(array_keys(config(Billing::class)->docTypes), fn ($t) => $this->access()->can('create_draft', $t)))[0] ?? 'tax_invoice');
        }
        $contact = null;
        if ($cid = (int) $this->request->getGet('contact')) {
            $contact = (new \App\Services\Contacts\ContactService($this->db))->billingView($cid);
        }

        return $this->form(null, $type, $contact);
    }

    public function edit($id)
    {
        $doc = $this->load($this->idOr404($id));
        if ($doc['status'] !== 'draft') {
            return redirect()->to(site_url('billing/documents/' . $doc['id']))->with('error', 'Only drafts can be edited.');
        }
        if (! $this->access()->can('edit_draft', $doc['doc_type'])) {
            $this->deny();
        }

        return $this->form($doc, $doc['doc_type'], (new \App\Services\Contacts\ContactService($this->db))->billingView((int) $doc['contact_id']));
    }

    private function form(?array $doc, string $type, ?array $contact)
    {
        $company = $this->db->table('company_settings')->where('id', 1)->get()->getRowArray() ?? [];
        $lines   = $doc ? $this->db->table('billing_line_items')->where('document_id', $doc['id'])->orderBy('line_no')->get()->getResultArray() : [];
        $issue   = $doc['issue_date'] ?? date('Y-m-d');
        $types   = array_filter(config(Billing::class)->docTypes, fn ($d, $k) => $this->access()->can('create_draft', $k), ARRAY_FILTER_USE_BOTH);
        $original = null;
        if (in_array($type, ['credit_note', 'debit_note'], true) && ! empty($doc['original_document_id'])) {
            $original = $this->db->table('billing_documents')->select('id, document_number, issue_date, grand_total')->where('id', $doc['original_document_id'])->get()->getRowArray();
        }

        return $this->render('billing/form', [
            'pageTitle'   => $doc ? 'Edit ' . doc_type_label($type) . ' ' . $doc['draft_ref'] : 'Create document',
            'breadcrumbs' => [['Billing'], [$doc ? $doc['draft_ref'] : 'Create document']],
            'doc'         => $doc,
            'type'        => $type,
            'types'       => $types,
            'contact'     => $contact,
            'lines'       => $lines,
            'company'     => $company,
            'states'      => $this->db->table('states')->orderBy('is_special')->orderBy('name')->get()->getResultArray(),
            'rates'       => $this->db->table('tax_rates')->where('is_active', 1)->where('effective_from <=', $issue)->groupStart()->where('effective_to', null)->orWhere('effective_to >=', $issue)->groupEnd()->orderBy('rate')->get()->getResultArray(),
            'treatments'  => $this->db->table('tax_treatments')->where('is_enabled', 1)->orderBy('sort_order')->get()->getResultArray(),
            'services'    => $this->db->table('services s')->select('s.id, s.code, s.name, s.description, s.sac_code, s.unit, s.default_rate, s.default_tax_treatment, t.rate AS gst_rate')
                ->join('tax_rates t', 't.id = s.default_tax_rate_id', 'left')->where('s.is_active', 1)->orderBy('s.name')->get()->getResultArray(),
            'banks'       => $this->db->table('bank_cash_accounts')->select('id, display_name, is_default_on_invoice')->where(['show_on_invoice' => 1, 'is_active' => 1])->orderBy('display_priority')->get()->getResultArray(),
            'templates'   => $this->db->table('document_templates')->select('id, name, version')->where('is_active', 1)->get()->getResultArray(),
            'canChangeBank' => $this->can('billing.change_bank_template'),
            'canOverrideBillTo' => $this->can('contacts.edit'),
            'original'    => $original,
            'supplierState' => $company['state_code'] ? $this->db->table('states')->where('code', $company['state_code'])->get()->getRowArray() : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        $in = $this->request->getPost();
        $in['lines'] = array_values((array) ($in['lines'] ?? []));

        return $in;
    }

    public function create()
    {
        try {
            $id = service('billing')->saveDraft($this->input());
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return $this->afterSave($id, 'Draft saved. No document number is used until you finalize.');
    }

    public function update($id)
    {
        $id = $this->idOr404($id);
        $this->load($id);

        try {
            service('billing')->saveDraft($this->input(), $id);
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return $this->afterSave($id, 'Draft updated.');
    }

    private function afterSave(int $id, string $msg)
    {
        $next = (string) $this->request->getPost('after');
        if ($next === 'finalize' && $this->wantsFinalizeRedirect($id)) {
            return redirect()->to(site_url('billing/documents/' . $id . '?finalize=1'))->with('success', $msg);
        }

        return redirect()->to(site_url('billing/documents/' . $id))->with('success', $msg);
    }

    private function wantsFinalizeRedirect(int $id): bool
    {
        $doc = service('billing')->find($id);

        return $this->access()->can('finalize', $doc['doc_type']);
    }

    /** Live calculation for the form (JSON). Server is the single source of truth. */
    public function calc()
    {
        $in    = $this->request->getJSON(true) ?? $this->request->getPost();
        $lines = array_values((array) ($in['lines'] ?? []));
        $lines = array_values(array_filter($lines, static fn ($l) => trim((string) ($l['item_name'] ?? '')) !== '' || (float) ($l['unit_rate'] ?? 0) != 0.0));
        $type  = (string) ($in['doc_type'] ?? 'tax_invoice');
        if (! $this->access()->can('create_draft', $type) && ! $this->access()->can('edit_draft', $type)) {
            $this->deny();
        }
        $calc = service('billing')->compute([
            'doc_type' => $type, 'contact_id' => (int) ($in['contact_id'] ?? 0), 'issue_date' => ymd((string) ($in['issue_date'] ?? '')) ?? date('Y-m-d'),
            'place_of_supply_state_code' => $in['place_of_supply_state_code'] ?? null, 'gst_type' => ($in['gst_type'] ?? '') ?: null,
            'is_sez_recipient' => ! empty($in['is_sez_recipient']), 'export_type' => $in['export_type'] ?? 'none', 'reverse_charge' => ! empty($in['reverse_charge']),
            'price_mode' => $in['price_mode'] ?? null,
        ], array_map(static fn ($l) => $l + ['qty' => '1', 'unit_rate' => '0'], $lines));

        // Preview what each GST type would produce on the current lines.
        $preview = [];
        foreach (['cgst_sgst', 'cgst_utgst', 'igst'] as $t) {
            $p = \App\Services\Billing\GstCalculator::compute(['gst_type' => $t, 'price_mode' => $in['price_mode'] ?? 'exclusive'], array_map(static fn ($l) => $l + ['qty' => '1', 'unit_rate' => '0'], $lines),
                array_column($this->db->table('tax_treatments')->get()->getResultArray(), 'charges_tax', 'code'));
            $preview[$t] = ['summary' => $p['summary'], 'tax_total' => $p['totals']['tax_total'], 'grand_total' => $p['totals']['grand_total']];
        }

        return $this->json([
            'lines' => array_map(static fn ($l) => array_intersect_key($l, array_flip(['taxable_value', 'cgst_amount', 'sgst_amount', 'utgst_amount', 'igst_amount', 'cess_amount', 'line_total', 'discount_amount', 'gross_amount'])), $calc['lines']),
            'totals'  => $calc['totals'],
            'summary' => $calc['summary'],
            'advice'  => $calc['advice'],
            'preview' => $preview,
            'words'   => \App\Libraries\AmountInWords::inr($calc['totals']['grand_total']),
        ]);
    }

    // ---- view & actions ----------------------------------------------

    public function show($id)
    {
        $doc   = $this->load($this->idOr404($id));
        $lines = $this->db->table('billing_line_items')->where('document_id', $doc['id'])->orderBy('line_no')->get()->getResultArray();
        $data  = [
            'pageTitle'   => doc_type_label($doc['doc_type']) . ' ' . ($doc['document_number'] ?? $doc['draft_ref']),
            'breadcrumbs' => [['Billing'], [doc_type_label($doc['doc_type']) . 's', 'billing/documents?type=' . $this->listType($doc['doc_type'])], [$doc['document_number'] ?? $doc['draft_ref']]],
            'doc'         => $doc,
            'lines'       => $lines,
            'taxes'       => $this->db->table('billing_taxes')->where('document_id', $doc['id'])->get()->getResultArray(),
            'advice'      => json_decode((string) $doc['tax_advice'], true) ?: ['messages' => [], 'status' => 'ok'],
            'state'       => $this->db->table('states')->where('code', $doc['place_of_supply_state_code'] ?? '')->get()->getRowArray(),
            'payments'    => [],
            'notes'       => $this->db->table('billing_documents')->select('id, doc_type, status, document_number, draft_ref, issue_date, grand_total')->where('original_document_id', $doc['id'])->get()->getResultArray(),
            'original'    => $doc['original_document_id'] ? $this->db->table('billing_documents')->select('id, doc_type, document_number, issue_date, grand_total')->where('id', $doc['original_document_id'])->get()->getRowArray() : null,
            'history'     => $this->db->table('audit_logs a')->select('a.occurred_at, a.action, a.summary, p.full_name')->join('user_profiles p', 'p.user_id = a.user_id', 'left')
                ->where(['a.entity_type' => 'billing_document', 'a.entity_id' => $doc['id']])->orderBy('a.id')->get()->getResultArray(),
            'canFinalize' => $doc['status'] === 'draft' && $this->access()->can('finalize', $doc['doc_type']),
            'canEdit'     => $doc['status'] === 'draft' && $this->access()->can('edit_draft', $doc['doc_type']),
            'canCancel'   => $doc['status'] === 'finalized' && ! in_array($doc['doc_type'], ['estimate', 'quotation', 'proforma'], true) && $this->access()->can('cancel', $doc['doc_type']),
            'canPdf'      => $this->access()->can('download', $doc['doc_type']) || $this->access()->can('print', $doc['doc_type']) || ($doc['status'] === 'draft' && $this->access()->can('create_draft', $doc['doc_type'])),
            'canSign'     => $doc['status'] === 'draft' && service('billing')->canSign($doc['doc_type'], (int) $this->userId),
            'canOverride' => $this->can('billing.tax.override'),
            'cashAccounts'=> $this->can('finance.receipt.create') ? $this->db->table('bank_cash_accounts')->select('id, display_name, account_kind')->where('is_active', 1)->orderBy('display_priority')->get()->getResultArray() : [],
            'snapshot'    => $doc['status'] !== 'draft' ? $this->db->table('billing_document_snapshots')->select('content_hash, created_at')->where('document_id', $doc['id'])->get()->getRowArray() : null,
            'company'     => $this->db->table('company_settings')->select('einvoice_applicable')->where('id', 1)->get()->getRowArray(),
        ];
        if ($this->can('finance.receipt.view')) {
            $data['payments'] = $this->db->table('payment_allocations a')->select('a.amount, a.allocated_at, a.reversed_at, r.id AS receipt_id, r.receipt_number, r.receipt_date, r.mode, r.status')
                ->join('payment_receipts r', 'r.id = a.receipt_id')->where('a.document_id', $doc['id'])->orderBy('a.id')->get()->getResultArray();
        }

        return $this->render('billing/show', $data);
    }

    private function listType(string $docType): string
    {
        return match ($docType) {
            'quotation', 'proforma' => 'estimate',
            'debit_note' => 'credit_note',
            default => $docType,
        };
    }

    public function finalize($id)
    {
        $doc = $this->load($this->idOr404($id));

        try {
            $res = service('billing')->finalize((int) $doc['id'], [
                'apply_signature'         => (bool) $this->request->getPost('apply_signature'),
                'tax_review_reason'       => (string) $this->request->getPost('tax_review_reason'),
                'paid_at_counter_account' => $this->request->getPost('paid_at_counter') ? (int) $this->request->getPost('paid_account_id') : null,
                'paid_mode'               => (string) ($this->request->getPost('paid_mode') ?: 'cash'),
                'reason_code'             => (string) $this->request->getPost('reason_code'),
                'reason_text'             => (string) $this->request->getPost('reason_text'),
            ]);
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('billing/documents/' . $doc['id']))->with('success', doc_type_label($doc['doc_type']) . ' finalized as ' . $res['document_number'] . '.' . ($res['receipt_id'] ? ' Counter payment recorded.' : ''));
    }

    public function pdf($id)
    {
        $doc = $this->load($this->idOr404($id));
        $mayDownload = $this->access()->can('download', $doc['doc_type']) || $this->access()->can('print', $doc['doc_type']);
        if (! $mayDownload && ! ($doc['status'] === 'draft' && ($this->access()->can('create_draft', $doc['doc_type']) || $this->access()->can('edit_draft', $doc['doc_type'])))) {
            $this->deny();
        }
        [$bytes, $name] = service('documentPdf')->render((int) $doc['id'], (string) $this->request->getGet('copy'));
        if ($doc['status'] !== 'draft') {
            service('audit')->log('billing.pdf_' . ($this->request->getGet('download') ? 'downloaded' : 'viewed'), 'billing_document', (int) $doc['id'], null, null, 'PDF ' . ($doc['document_number'] ?? ''));
        }

        return $this->pdfResponse($bytes, $name, (bool) $this->request->getGet('download'));
    }

    public function cancel($id)
    {
        $doc = $this->load($this->idOr404($id));

        try {
            service('billing')->cancel((int) $doc['id'], (string) $this->request->getPost('reason'));
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Document cancelled. Its number stays in the register as cancelled and will never be reused.');
    }

    public function deleteDraft($id)
    {
        $doc = $this->load($this->idOr404($id));

        try {
            service('billing')->deleteDraft((int) $doc['id']);
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('billing/documents?status=draft'))->with('success', 'Draft deleted.');
    }

    public function duplicate($id)
    {
        $doc = $this->load($this->idOr404($id));
        if (! $this->access()->can('create_draft', $doc['doc_type'])) {
            $this->deny();
        }
        $new = service('billing')->duplicate((int) $doc['id']);

        return redirect()->to(site_url('billing/documents/' . $new . '/edit'))->with('success', 'Copied into a new draft (no number, no payments). Review and save.');
    }

    public function convert($id)
    {
        $doc = $this->load($this->idOr404($id));
        if (! in_array($doc['doc_type'], ['estimate', 'quotation', 'proforma'], true)) {
            return redirect()->back()->with('error', 'Only estimates, quotations and proforma invoices can be converted.');
        }
        $to = (string) ($this->request->getPost('to') ?: 'tax_invoice');
        if (! $this->access()->can('create_draft', $to)) {
            $this->deny();
        }
        $new = service('billing')->duplicate((int) $doc['id'], $to);

        return redirect()->to(site_url('billing/documents/' . $new . '/edit'))->with('success', 'Converted into a ' . doc_type_label($to) . ' draft. The estimate is marked converted when the invoice is finalized.');
    }

    public function creditNote($id)
    {
        $doc = $this->load($this->idOr404($id));
        if ($doc['status'] !== 'finalized' || ! in_array($doc['doc_type'], ['tax_invoice', 'cash_memo', 'bill_of_supply'], true)) {
            return redirect()->back()->with('error', 'Credit/debit notes can be raised only against finalized invoices, cash memos or bills of supply.');
        }
        $type = $this->request->getPost('type') === 'debit_note' ? 'debit_note' : 'credit_note';
        $new  = service('billing')->duplicate((int) $doc['id'], $type);

        return redirect()->to(site_url('billing/documents/' . $new . '/edit'))->with('success', doc_type_label($type) . ' draft created from ' . $doc['document_number'] . '. Adjust the lines to the amount being credited/debited.');
    }

    /** Record an IRN obtained from the government e-invoice portal (no fabrication). */
    public function recordIrn($id)
    {
        $doc = $this->load($this->idOr404($id));
        $irn = strtolower(trim((string) $this->request->getPost('irn')));
        if ($doc['status'] !== 'finalized' || ! preg_match('/^[a-f0-9]{64}$/', $irn)) {
            return redirect()->back()->with('error', 'Enter the 64-character IRN exactly as issued by the IRP.');
        }
        $data = ['einvoice_irn' => $irn, 'einvoice_ack_no' => mb_substr(trim((string) $this->request->getPost('ack_no')), 0, 30) ?: null, 'einvoice_ack_date' => ymd((string) $this->request->getPost('ack_date'))];
        $this->db->table('billing_documents')->where('id', $doc['id'])->update($data);
        service('audit')->log('billing.irn_recorded', 'billing_document', (int) $doc['id'], null, $data, 'IRN recorded from IRP portal');

        return redirect()->back()->with('success', 'IRN recorded. (The printed snapshot is unchanged; the IRN is shown on the document record.)');
    }

    /** Load with record-scope check (404 if outside scope, to avoid enumeration). */
    private function load(int $id): array
    {
        $doc = $this->db->table('billing_documents')->where('id', $id)->get()->getRowArray();
        if ($doc === null || ! $this->access()->canView($doc)) {
            $this->notFound();
        }

        return $doc;
    }
}
