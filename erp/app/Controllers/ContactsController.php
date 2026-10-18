<?php

namespace App\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Libraries\Gstin;
use App\Libraries\SheetIO;
use App\Services\Contacts\ContactService;
use App\Services\Reports\ExportService;

/** Clients, vendors and prospects (unified master). */
class ContactsController extends BaseController
{
    private function service(): ContactService
    {
        return new ContactService($this->db);
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return [
            'q'      => trim((string) $this->request->getGet('q')),
            'type'   => (string) $this->request->getGet('type'),
            'state'  => (string) $this->request->getGet('state'),
            'status' => (string) ($this->request->getGet('status') ?: 'active'),
        ];
    }

    private function query(array $f)
    {
        $b = $this->db->table('contacts c')->select('c.*, s.name AS state_name')->join('states s', 's.code = c.state_code', 'left')->where('c.deleted_at', null);
        if ($f['q'] !== '') {
            $digits = preg_replace('/\D/', '', $f['q']);
            $b->groupStart()->like('c.name', $f['q'])->orLike('c.display_name', $f['q'])->orLike('c.contact_code', $f['q'])->orLike('c.gstin', strtoupper($f['q']))->orLike('c.email', $f['q']);
            if (strlen((string) $digits) >= 4) {
                $b->orLike('c.phone_normalized', $digits);
            }
            $b->groupEnd();
        }
        match ($f['type']) {
            'customer' => $b->where('c.is_customer', 1),
            'vendor'   => $b->where('c.is_vendor', 1),
            'prospect' => $b->where('c.is_prospect', 1),
            default    => null,
        };
        if ($f['state'] !== '') {
            $b->where('c.state_code', $f['state']);
        }
        if ($f['status'] === 'active') {
            $b->where('c.is_active', 1);
        } elseif ($f['status'] === 'inactive') {
            $b->where('c.is_active', 0);
        }

        return $b;
    }

    public function index()
    {
        $f = $this->filters();
        [$page, $perPage, $offset] = $this->paging(25);
        $b     = $this->query($f);
        $total = (clone $b)->countAllResults();
        $rows  = $b->orderBy('c.name')->limit($perPage, $offset)->get()->getResultArray();
        $out   = [];
        if ($this->can('contacts.finance_view') && $rows !== []) {
            $ids = array_column($rows, 'id');
            foreach ($this->db->table('billing_documents')->select('contact_id, SUM(balance_due) due')->whereIn('contact_id', $ids)->where('status', 'finalized')
                ->whereIn('doc_type', ['tax_invoice', 'bill_of_supply', 'cash_memo', 'debit_note'])->groupBy('contact_id')->get()->getResultArray() as $r) {
                $out[(int) $r['contact_id']] = $r['due'];
            }
        }

        return $this->render('contacts/index', [
            'pageTitle' => 'Clients & vendors', 'breadcrumbs' => [['Clients & vendors']],
            'rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage, 'f' => $f, 'outstanding' => $out,
            'states' => $this->db->table('states')->orderBy('name')->get()->getResultArray(),
        ]);
    }

    public function export()
    {
        $f    = $this->filters();
        $rows = $this->query($f)->orderBy('c.name')->get()->getResultArray();
        $cols = [
            ['key' => 'contact_code', 'label' => 'Code'], ['key' => 'name', 'label' => 'Name', 'width' => 32], ['key' => 'type', 'label' => 'Type'],
            ['key' => 'contact_person', 'label' => 'Contact person'], ['key' => 'phone', 'label' => 'Phone'], ['key' => 'email', 'label' => 'Email', 'width' => 28],
            ['key' => 'gstin', 'label' => 'GSTIN'], ['key' => 'gst_registration_type', 'label' => 'GST registration'], ['key' => 'state_code', 'label' => 'State code'],
            ['key' => 'state_name', 'label' => 'State'], ['key' => 'city', 'label' => 'City'], ['key' => 'pincode', 'label' => 'PIN'],
            ['key' => 'industry', 'label' => 'Industry'], ['key' => 'credit_days', 'label' => 'Credit days', 'type' => 'int'],
        ];
        foreach ($rows as &$r) {
            $r['type'] = implode('/', array_filter([$r['is_customer'] ? 'Customer' : null, $r['is_vendor'] ? 'Vendor' : null, $r['is_prospect'] ? 'Prospect' : null]));
        }
        unset($r);

        return (new ExportService($this->db))->download('contacts', (string) $this->request->getGet('format'), 'contacts',
            [['title' => 'Contacts', 'columns' => $cols, 'rows' => $rows]], $f, ['Report' => 'Clients & vendors']);
    }

    public function new()
    {
        return $this->form(null);
    }

    public function edit($id)
    {
        return $this->form($this->find($this->idOr404($id)));
    }

    private function form(?array $c)
    {
        $billing = $c ? $this->db->table('contact_addresses')->where(['contact_id' => $c['id'], 'address_type' => 'billing'])->get()->getRowArray() : null;
        $service = $c ? $this->db->table('contact_addresses')->where(['contact_id' => $c['id'], 'address_type' => 'service'])->get()->getRowArray() : null;

        return $this->render('contacts/form', [
            'pageTitle'   => $c ? 'Edit ' . $c['name'] : 'New client / vendor',
            'breadcrumbs' => [$this->can('contacts.view_all') ? ['Clients & vendors', 'contacts'] : ['Clients & vendors'], [$c ? $c['name'] : 'New']],
            'c'           => $c,
            'billing'     => $billing,
            'service'     => $service,
            'people'      => $c ? $this->db->table('contact_people')->where('contact_id', $c['id'])->get()->getResultArray() : [],
            'states'      => $this->db->table('states')->orderBy('name')->get()->getResultArray(),
            'sources'     => array_column($this->db->table('lead_sources')->where('is_active', 1)->get()->getResultArray(), 'name'),
        ]);
    }

    public function create()
    {
        try {
            $r = $this->service()->create($this->request->getPost(), (bool) $this->request->getPost('allow_duplicate'));
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }
        // users who may create but not browse contacts (e.g. CRM managers) stay on the form
        $code     = (string) ($this->db->table('contacts')->select('contact_code')->where('id', $r['id'])->get()->getRow()->contact_code ?? '');
        $redirect = redirect()->to(site_url($this->can('contacts.view_all') ? 'contacts/' . $r['id'] : 'contacts/new'))->with('success', 'Contact ' . $code . ' saved.');

        return $r['warnings'] ? $redirect->with('warning', implode(' ', $r['warnings'])) : $redirect;
    }

    public function update($id)
    {
        $id = $this->idOr404($id);
        $this->find($id);

        try {
            $warnings = $this->service()->update($id, $this->request->getPost());
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }
        $redirect = redirect()->to(site_url($this->can('contacts.view_all') ? 'contacts/' . $id : 'contacts/' . $id . '/edit'))->with('success', 'Contact updated. Finalized documents keep the details printed at issue.');

        return $warnings ? $redirect->with('warning', implode(' ', $warnings)) : $redirect;
    }

    public function show($id)
    {
        $c = $this->find($this->idOr404($id));
        $data = [
            'pageTitle'   => $c['name'],
            'breadcrumbs' => [['Clients & vendors', 'contacts'], [$c['name']]],
            'c'           => $c,
            'state'       => $c['state_code'] ? $this->db->table('states')->where('code', $c['state_code'])->get()->getRowArray() : null,
            'addresses'   => $this->db->table('contact_addresses')->where('contact_id', $c['id'])->get()->getResultArray(),
            'people'      => $this->db->table('contact_people')->where('contact_id', $c['id'])->get()->getResultArray(),
            'files'       => $this->db->table('contact_files cf')->select('cf.*, f.original_name, f.size_bytes, f.id AS file_id')->join('stored_files f', 'f.id = cf.stored_file_id')->where('cf.contact_id', $c['id'])->get()->getResultArray(),
            'docs'        => [],
            'receipts'    => [],
            'summary'     => null,
            'leads'       => [],
            'visits'      => [],
            'expenses'    => [],
        ];
        $access = service('billingAccess');
        if ($access->visibleTypes() !== []) {
            $b = $this->db->table('billing_documents d')->select('d.id, d.doc_type, d.status, d.document_number, d.draft_ref, d.issue_date, d.due_date, d.grand_total, d.balance_due, d.payment_status')->where('d.contact_id', $c['id']);
            $access->applyScope($b, 'd');
            $data['docs'] = $b->orderBy('d.issue_date', 'DESC')->limit(50)->get()->getResultArray();
        }
        if ($this->can('finance.receipt.view')) {
            $data['receipts'] = $this->db->table('payment_receipts')->where('contact_id', $c['id'])->orderBy('receipt_date', 'DESC')->limit(50)->get()->getResultArray();
        }
        if ($this->can('contacts.finance_view')) {
            $row = $this->db->query(
                "SELECT COALESCE(SUM(CASE WHEN doc_type IN ('tax_invoice','bill_of_supply','cash_memo','debit_note') THEN grand_total ELSE 0 END),0) billed,
                        COALESCE(SUM(CASE WHEN doc_type = 'credit_note' THEN grand_total ELSE 0 END),0) credited,
                        COALESCE(SUM(CASE WHEN doc_type IN ('tax_invoice','bill_of_supply','cash_memo','debit_note') THEN balance_due ELSE 0 END),0) due
                 FROM billing_documents WHERE contact_id = ? AND status = 'finalized'",
                [$c['id']]
            )->getRowArray();
            $rec = $this->db->query("SELECT COALESCE(SUM(CASE WHEN receipt_type='receipt' THEN amount ELSE -amount END),0) received, COALESCE(SUM(CASE WHEN receipt_type='receipt' THEN unapplied_amount ELSE 0 END),0) advance FROM payment_receipts WHERE contact_id = ? AND status = 'active'", [$c['id']])->getRowArray();
            $data['summary'] = $row + $rec + ['opening' => ($c['opening_balance_type'] === 'cr' ? '-' : '') . $c['opening_balance']];
        }
        $leadAccess = service('leadAccess');
        if ($leadAccess->scope()['mode'] !== 'none') {
            $b = $this->db->table('leads l')->select('l.id, l.lead_code, l.company_name, l.next_followup_at, s.name AS stage')->join('crm_stages s', 's.id = l.stage_id')->where('l.contact_id', $c['id'])->where('l.deleted_at', null);
            $leadAccess->applyScope($b, 'l');
            $data['leads'] = $b->get()->getResultArray();
        }
        if ($this->can('crm.visit.view')) {
            $data['visits'] = $this->db->table('visits v')->select('v.*, p.full_name AS visitor')->join('user_profiles p', 'p.user_id = v.visitor_id', 'left')->where('v.contact_id', $c['id'])->orderBy('v.scheduled_at', 'DESC')->limit(20)->get()->getResultArray();
        }
        if ($this->can('finance.expense.view_all')) {
            $data['expenses'] = $this->db->table('expenses')->where('vendor_contact_id', $c['id'])->where('deleted_at', null)->orderBy('expense_date', 'DESC')->limit(50)->get()->getResultArray();
        }

        return $this->render('contacts/show', $data);
    }

    public function archive($id)
    {
        $c = $this->find($this->idOr404($id));
        $open = $this->db->table('billing_documents')->where(['contact_id' => $c['id'], 'status' => 'finalized'])->where('balance_due >', 0)->countAllResults();
        if ($open > 0) {
            return redirect()->back()->with('error', 'This contact has ' . $open . ' unpaid document(s). Settle or credit them before archiving.');
        }
        $this->db->table('contacts')->where('id', $c['id'])->update(['is_active' => 0, 'updated_by' => $this->userId]);
        service('audit')->log('contact.archived', 'contact', (int) $c['id'], null, null, 'Contact archived (inactive)');

        return redirect()->to(site_url('contacts'))->with('success', 'Contact marked inactive. History is preserved.');
    }

    public function mergeForm($id)
    {
        $c = $this->find($this->idOr404($id));

        return $this->render('contacts/merge', [
            'pageTitle' => 'Merge duplicate', 'breadcrumbs' => [['Clients & vendors', 'contacts'], [$c['name'], 'contacts/' . $c['id']], ['Merge']],
            'c' => $c, 'suggest' => $this->service()->duplicates($c['gstin'], $c['phone'], (int) $c['id']),
        ]);
    }

    public function merge($id)
    {
        $source = $this->find($this->idOr404($id));
        $target = (int) ($this->request->getPost('target_id_manual') ?: $this->request->getPost('target_id'));

        try {
            $this->service()->merge((int) $source['id'], $target);
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('contacts/' . $target))->with('success', $source['contact_code'] . ' was merged into this contact. All invoices, receipts, leads and history now point here.');
    }

    public function uploadFile($id)
    {
        $c = $this->find($this->idOr404($id));
        $file = $this->request->getFile('file');
        if ($file === null) {
            return redirect()->back()->with('error', 'Choose a file.');
        }

        try {
            $fid = service('files')->storeUpload($file, 'doc', 'contact', 'contact', (int) $c['id']);
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
        $this->db->table('contact_files')->insert(['contact_id' => $c['id'], 'stored_file_id' => $fid, 'label' => $this->post('label'), 'created_by' => $this->userId]);
        service('audit')->log('contact.file_uploaded', 'contact', (int) $c['id'], null, ['file' => $file->getClientName()], 'Attachment added');

        return redirect()->back()->with('success', 'Attachment uploaded.');
    }

    // ---- JSON helpers -------------------------------------------------

    /** Customer autocomplete for billing — returns billing-safe fields only. */
    public function lookup()
    {
        $q     = trim((string) $this->request->getGet('q'));
        $kind  = (string) $this->request->getGet('kind');
        $b     = $this->db->table('contacts')->select('id')->where('deleted_at', null)->where('is_active', 1);
        if ($kind === 'vendor') {
            $b->where('is_vendor', 1);
        } elseif ($kind !== 'any') {
            $b->groupStart()->where('is_customer', 1)->orWhere('is_prospect', 1)->groupEnd();
        }
        if ($q !== '') {
            $digits = preg_replace('/\D/', '', $q);
            $b->groupStart()->like('name', $q)->orLike('display_name', $q)->orLike('gstin', strtoupper($q))->orLike('contact_code', $q);
            if (strlen((string) $digits) >= 4) {
                $b->orLike('phone_normalized', $digits);
            }
            $b->groupEnd();
        } elseif (! $this->can('contacts.view_all')) {
            // billing operators must type to search; no browsing of the full client list
            return $this->json(['items' => []]);
        }
        $ids = array_column($b->orderBy('name')->limit(15)->get()->getResultArray(), 'id');
        $svc = $this->service();

        return $this->json(['items' => array_values(array_filter(array_map(static fn ($id) => $svc->billingView((int) $id), $ids)))]);
    }

    public function billing($id)
    {
        $c = $this->service()->billingView($this->idOr404($id)) ?? $this->notFound();

        return $this->json(['contact' => $c]);
    }

    public function duplicates()
    {
        $exclude = $this->request->getGet('exclude') ? (int) $this->request->getGet('exclude') : null;
        $rows = $this->service()->duplicates((string) $this->request->getGet('gstin'), (string) $this->request->getGet('phone'), $exclude);

        return $this->json(['items' => array_map(static fn ($r) => ['id' => (int) $r['id'], 'label' => $r['contact_code'] . ' — ' . $r['name'], 'gstin' => $r['gstin'], 'url' => site_url('contacts/' . $r['id'])], $rows)]);
    }

    public function gstinCheck()
    {
        $v = Gstin::validate((string) $this->request->getGet('gstin'));
        $state = $v['state_code'] ? $this->db->table('states')->where('code', $v['state_code'])->get()->getRowArray() : null;

        return $this->json(['valid' => $v['valid'], 'error' => $v['error'], 'state_code' => $v['state_code'], 'state_name' => $state['name'] ?? null, 'pan' => $v['pan'],
            'note' => 'Structure and check digit verified; registration status is not checked online.']);
    }

    // ---- Import -------------------------------------------------------

    private const IMPORT_COLUMNS = ['name', 'type', 'contact_person', 'phone', 'alt_phone', 'whatsapp', 'email', 'gstin', 'gst_registration_type', 'pan',
        'state_code', 'city', 'district', 'pincode', 'billing_line1', 'billing_line2', 'industry', 'source', 'category', 'tags', 'credit_days', 'opening_balance', 'notes'];

    public function importForm()
    {
        return $this->render('contacts/import', ['pageTitle' => 'Import clients & vendors', 'breadcrumbs' => [['Clients & vendors', 'contacts'], ['Import']], 'columns' => self::IMPORT_COLUMNS, 'preview' => null]);
    }

    public function importTemplate()
    {
        $cols = array_map(static fn ($c) => ['key' => $c, 'label' => $c], self::IMPORT_COLUMNS);
        $bytes = SheetIO::xlsx([[
            'title' => 'Contacts', 'columns' => $cols,
            'rows'  => [['name' => 'Example Clinic Pvt Ltd', 'type' => 'customer', 'contact_person' => 'Dr. A. Sharma', 'phone' => '9800000000', 'email' => 'accounts@example.com',
                'gst_registration_type' => 'unregistered', 'state_code' => '08', 'city' => 'Bhiwadi', 'pincode' => '301019', 'billing_line1' => 'Shop 1, Example Market', 'credit_days' => '15', 'notes' => 'EXAMPLE ROW — delete before importing']],
            'notes' => ['type: customer | vendor | prospect | customer+vendor', 'gst_registration_type: registered | unregistered | composition | consumer | sez | overseas', 'state_code: 2-digit GST state code (e.g. 08 = Rajasthan). Leave blank to derive from GSTIN.'],
        ]]);

        return $this->response->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', 'attachment; filename="contacts_import_template.xlsx"')->setBody($bytes);
    }

    public function importPreview()
    {
        $file = $this->request->getFile('file');
        if ($file === null) {
            return redirect()->back()->with('error', 'Choose an .xlsx or .csv file.');
        }

        try {
            $fid = service('files')->storeUpload($file, 'sheet', 'import');
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return $this->render('contacts/import', [
            'pageTitle' => 'Import preview', 'breadcrumbs' => [['Clients & vendors', 'contacts'], ['Import', 'contacts/import'], ['Preview']],
            'columns' => self::IMPORT_COLUMNS, 'preview' => $this->analyse($fid), 'fileId' => $fid,
        ]);
    }

    public function importCommit()
    {
        $fid  = (int) $this->request->getPost('file_id');
        $file = service('files')->find($fid);
        if ($file === null || $file['category'] !== 'import' || (int) $file['uploaded_by'] !== (int) $this->userId) {
            $this->notFound();
        }
        $preview = $this->analyse($fid);
        $svc     = $this->service();
        $ok      = 0;
        $failed  = [];
        foreach ($preview['rows'] as $r) {
            if ($r['status'] !== 'ok' && ! ($r['status'] === 'duplicate' && $this->request->getPost('include_duplicates'))) {
                $failed[] = $r;
                continue;
            }
            try {
                $svc->create($r['input'], true);
                $ok++;
            } catch (BusinessRuleException $e) {
                $failed[] = $r + ['message' => implode(' ', $e->errors() ?: [$e->getMessage()])];
            }
        }
        service('audit')->log('contact.imported', 'contact', null, null, ['imported' => $ok, 'skipped' => count($failed)], "Imported {$ok} contacts");
        session()->setFlashdata('success', "{$ok} contact(s) imported." . ($failed ? ' ' . count($failed) . ' row(s) skipped — download the error file below.' : ''));
        if ($failed !== []) {
            $cols = array_merge([['key' => 'row', 'label' => 'Row', 'type' => 'int'], ['key' => 'message', 'label' => 'Problem', 'width' => 60]], array_map(static fn ($c) => ['key' => $c, 'label' => $c], self::IMPORT_COLUMNS));
            $rows = array_map(static fn ($r) => ['row' => $r['row'], 'message' => $r['message']] + $r['raw'], $failed);

            return (new \App\Services\Reports\ExportService($this->db))->download('contacts_import_errors', 'xlsx', 'contacts_import_errors', [['title' => 'Errors', 'columns' => $cols, 'rows' => $rows]]);
        }

        return redirect()->to(site_url('contacts'));
    }

    /** @return array{rows: list<array<string, mixed>>, counts: array<string, int>} */
    private function analyse(int $fileId): array
    {
        $file = service('files')->find($fileId);
        $data = SheetIO::read(service('files')->path($file), $file['original_name']);
        $svc  = $this->service();
        $out  = [];
        $seen = [];
        $counts = ['ok' => 0, 'error' => 0, 'duplicate' => 0];
        foreach (array_slice($data['rows'], 0, 2000) as $i => $raw) {
            $type  = strtolower((string) ($raw['type'] ?? 'customer'));
            $input = $raw + [
                'is_customer' => str_contains($type, 'customer') || $type === '' ? 1 : 0,
                'is_vendor'   => str_contains($type, 'vendor') ? 1 : 0,
                'is_prospect' => str_contains($type, 'prospect') ? 1 : 0,
            ];
            $row = ['row' => $i + 2, 'raw' => array_intersect_key($raw, array_flip(self::IMPORT_COLUMNS)), 'input' => $input, 'status' => 'ok', 'message' => ''];
            try {
                $n    = $svc->normalize($input);
                $key  = ($n['data']['gstin'] ?? '') . '|' . ($n['data']['phone_normalized'] ?? '');
                $dupe = $svc->duplicates($n['data']['gstin'], $n['data']['phone'], null);
                if ($dupe !== [] || ($key !== '|' && isset($seen[$key]))) {
                    $row['status']  = 'duplicate';
                    $row['message'] = $dupe ? 'Matches existing ' . $dupe[0]['contact_code'] . ' ' . $dupe[0]['name'] : 'Duplicate of an earlier row in this file';
                }
                $seen[$key] = true;
                if ($n['warnings']) {
                    $row['message'] = trim($row['message'] . ' ' . implode(' ', $n['warnings']));
                }
            } catch (BusinessRuleException $e) {
                $row['status']  = 'error';
                $row['message'] = implode(' ', $e->errors() ?: [$e->getMessage()]);
            }
            $counts[$row['status']]++;
            $out[] = $row;
        }

        return ['rows' => $out, 'counts' => $counts, 'truncated' => count($data['rows']) > 2000];
    }

    private function find(int $id): array
    {
        return $this->db->table('contacts')->where('id', $id)->where('deleted_at', null)->get()->getRowArray() ?? $this->notFound();
    }
}
