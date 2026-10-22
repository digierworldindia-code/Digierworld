<?php

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Libraries\Dec;
use App\Services\Settings\SettingsStore;

/**
 * Bank, UPI and cash account profiles. Account numbers and CIF are stored
 * encrypted and shown masked; full values only via an audited reveal for
 * users holding settings.bank.view_full / view_cif. CIF is never printed,
 * exported, emailed, synced or sent to AI.
 */
class BanksController extends BaseController
{
    /** @var array<string, array{0: int, 1: string}> field => [max length, label] */
    private const MAX_LENGTHS = [
        'display_name' => [120, 'Display name'], 'bank_name' => [120, 'Bank name'], 'beneficiary_name' => [150, 'Beneficiary name'],
        'branch' => [120, 'Branch'], 'upi_id' => [100, 'UPI ID'], 'classification_note' => [255, 'Classification'], 'cif' => [40, 'CIF'],
    ];

    public function index()
    {
        $rows = $this->db->table('bank_cash_accounts b')->select('b.*, l.code AS ledger_code')
            ->join('ledger_accounts l', 'l.id = b.ledger_account_id')
            ->orderBy('b.is_active', 'DESC')->orderBy('b.display_priority')->get()->getResultArray();

        return $this->render('settings/banks/index', [
            'pageTitle' => 'Bank & payment profiles', 'breadcrumbs' => [['Settings'], ['Bank & payment profiles']],
            'rows' => $rows, 'canEdit' => $this->can('settings.bank.edit'),
            'canFull' => $this->can('settings.bank.view_full'), 'canCif' => $this->can('settings.bank.view_cif'),
            'wizard' => $this->request->getGet('wizard') !== null,
        ]);
    }

    public function new()
    {
        return $this->form(null);
    }

    public function edit($id)
    {
        return $this->form($this->find((int) $id));
    }

    private function form(?array $acc)
    {
        return $this->render('settings/banks/form', [
            'pageTitle'   => $acc ? 'Edit: ' . $acc['display_name'] : 'Add bank / payment account',
            'breadcrumbs' => [['Settings'], ['Bank & payment profiles', 'settings/banks'], [$acc ? $acc['display_name'] : 'Add']],
            'a'           => $acc,
            'isSuper'     => $this->authz->isSuper(),
            'wizard'      => $this->request->getGet('wizard') !== null,
        ]);
    }

    public function create()
    {
        return $this->save(null);
    }

    public function update($id)
    {
        return $this->save($this->find((int) $id));
    }

    private function save(?array $before)
    {
        $kind      = in_array($this->post('account_kind'), ['cash', 'bank', 'upi_clearing', 'card_clearing', 'other'], true) ? $this->post('account_kind') : 'bank';
        $ownership = in_array($this->post('ownership'), ['company', 'owner_personal', 'other'], true) ? $this->post('ownership') : 'company';
        $acctNo    = preg_replace('/\s+/', '', (string) $this->request->getPost('account_number'));
        $cif       = trim((string) $this->request->getPost('cif'));
        $ifsc      = strtoupper(trim((string) $this->request->getPost('ifsc')));
        $upi       = trim((string) $this->request->getPost('upi_id'));
        $errors    = [];

        $display = trim((string) $this->request->getPost('display_name'));
        if ($display === '') {
            $errors['display_name'] = 'Give this account a recognisable name, e.g. "HDFC Current A/c".';
        }
        if ($kind === 'bank') {
            if (($before === null || $before['account_number_enc'] === null) && $acctNo === '') {
                $errors['account_number'] = 'Account number is required for a bank account.';
            }
            if ($acctNo !== '' && ! preg_match('/^[0-9A-Z]{6,20}$/i', $acctNo)) {
                $errors['account_number'] = 'Account number should be 6–20 letters/digits.';
            }
            if ($ifsc !== '' && ! preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
                $errors['ifsc'] = 'IFSC format is invalid (e.g. HDFC0001234).';
            }
        }
        if ($upi !== '' && ! preg_match('/^[\w.\-]{2,256}@[a-zA-Z][a-zA-Z0-9.\-]{1,64}$/', $upi)) {
            $errors['upi_id'] = 'UPI ID looks invalid (e.g. business@okhdfcbank).';
        }
        if ($ownership === 'owner_personal' && trim((string) $this->request->getPost('classification_note')) === '') {
            $errors['classification_note'] = 'Record how your accountant has classified business receipts into this personal account (e.g. owner clearing / capital).';
        }
        // column sizes: longer values would be rejected by the database
        foreach (self::MAX_LENGTHS as $field => [$max, $label]) {
            if (! isset($errors[$field]) && mb_strlen(trim((string) $this->request->getPost($field))) > $max) {
                $errors[$field] = "{$label} can be at most {$max} characters.";
            }
        }
        $priority = trim((string) $this->request->getPost('display_priority'));
        if ($priority !== '' && (! ctype_digit($priority) || (int) $priority > 9999)) {
            $errors['display_priority'] = 'Display priority is a whole number from 0 to 9999.';
        }
        $opening = str_replace([',', ' ', '₹'], '', trim((string) $this->request->getPost('opening_balance')));
        if ($opening !== '' && (! preg_match('/^-?\d{1,13}(\.\d{1,2})?$/', $opening))) {
            $errors['opening_balance'] = 'Enter the opening balance as an amount, e.g. 50000 or 50,000.00.';
        }
        $openingDate = trim((string) $this->request->getPost('opening_date'));
        if ($openingDate !== '' && ymd($openingDate) === null) {
            $errors['opening_date'] = 'Enter a valid date.';
        }
        if ($errors !== []) {
            return $this->backWithErrors($errors);
        }

        $showOnInvoice = $this->request->getPost('show_on_invoice') ? 1 : 0;
        $isDefault     = $this->request->getPost('is_default_on_invoice') ? 1 : 0;
        $data = [
            'account_kind'          => $kind,
            'ownership'             => $ownership,
            'classification_note'   => $this->post('classification_note'),
            'display_name'          => $display,
            'bank_name'             => $this->post('bank_name'),
            'beneficiary_name'      => $this->post('beneficiary_name'),
            'ifsc'                  => $ifsc ?: null,
            'branch'                => $this->post('branch'),
            'account_type'          => in_array($this->post('account_type'), ['current', 'savings', 'od', 'cc', 'wallet', 'other'], true) ? $this->post('account_type') : null,
            'upi_id'                => $upi ?: null,
            'display_priority'      => $priority === '' ? 100 : (int) $priority,
            'show_on_invoice'       => $showOnInvoice,
            'is_default_on_invoice' => $isDefault && $showOnInvoice ? 1 : 0,
            'opening_balance'       => Dec::round($opening === '' ? '0' : $opening),
            'opening_date'          => ymd($openingDate),
            'requires_review'       => $ownership === 'owner_personal' ? 1 : ($this->request->getPost('requires_review') ? 1 : 0),
            'is_active'             => $before === null ? 1 : ($this->request->getPost('is_active') ? 1 : 0),
            'updated_by'            => $this->userId,
        ];
        if (! $data['is_active']) {
            $data['is_default_on_invoice'] = 0; // an inactive account is never offered on new invoices
        }
        if ($data['opening_date'] === null && ! Dec::isZero($data['opening_balance'])) {
            // an opening balance needs a fixed date; otherwise each later save would move it to "today"
            $data['opening_date'] = $before['opening_date'] ?? date('Y-m-d');
        }
        if ($acctNo !== '') {
            $data['account_number_enc'] = SettingsStore::encrypt($acctNo);
            $data['account_last4']      = substr($acctNo, -4);
        }
        if ($cif !== '') {
            $data['cif_enc'] = SettingsStore::encrypt($cif);
        }
        // Personal accounts can only be printed after explicit owner approval.
        if ($ownership === 'owner_personal') {
            if ($this->authz->isSuper()) {
                $approved = (bool) $this->request->getPost('print_approved');
                if ($approved && empty($before['print_approved_by'])) {
                    $data['print_approved_by'] = $this->userId;
                    $data['print_approved_at'] = date('Y-m-d H:i:s');
                } elseif (! $approved) {
                    $data['print_approved_by'] = null;
                    $data['print_approved_at'] = null;
                }
            } else {
                $approved = ! empty($before['print_approved_by']); // non-owners cannot change approval
            }
            if (! $approved) {
                $data['show_on_invoice']       = 0;
                $data['is_default_on_invoice'] = 0;
            }
        }

        $this->db->transBegin();
        try {
            if ($data['is_default_on_invoice']) {
                $this->db->table('bank_cash_accounts')->update(['is_default_on_invoice' => 0]);
            }
            if ($before === null) {
                $subType = match (true) {
                    $kind === 'cash'               => 'cash',
                    $ownership === 'owner_personal' => 'owner_clearing',
                    $kind === 'upi_clearing'       => 'upi_clearing',
                    $kind === 'card_clearing'      => 'card_clearing',
                    default                        => 'bank',
                };
                $next = (int) ($this->db->query("SELECT COALESCE(MAX(CAST(code AS UNSIGNED)),1199) m FROM ledger_accounts WHERE code BETWEEN '1200' AND '1299'")->getRow()->m ?? 1199) + 1;
                $this->db->table('ledger_accounts')->insert([
                    'code' => (string) $next, 'name' => $display . ($ownership === 'owner_personal' ? ' (owner clearing)' : ''),
                    'account_group' => 'asset', 'sub_type' => $subType, 'is_system' => 0, 'created_by' => $this->userId,
                ]);
                $data['ledger_account_id'] = (int) $this->db->insertID();
                $data['code']              = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $display), 0, 10)) . '-' . $next;
                $data['created_by']        = $this->userId;
                $this->db->table('bank_cash_accounts')->insert($data);
                $id = (int) $this->db->insertID();
                if ($kind === 'bank') {
                    service('settingsStore')->markSetupStep('banks');
                }
            } else {
                $id = (int) $before['id'];
                $this->db->table('bank_cash_accounts')->where('id', $id)->update($data);
                $this->db->table('ledger_accounts')->where('id', $before['ledger_account_id'])->update(['name' => $display . ($ownership === 'owner_personal' ? ' (owner clearing)' : '')]);
            }
            service('ledger')->syncOpeningBalance($id);
            service('audit')->log($before ? 'bank_account.updated' : 'bank_account.created', 'bank_cash_account', $id, $before, $data, ($before ? 'Updated ' : 'Created ') . $display);
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        $msg = 'Account saved.';
        if ($ownership === 'owner_personal' && empty($approved)) {
            $msg .= ' This personal account will not be printed on invoices until an Owner approves it.';
        }

        return redirect()->to(site_url('settings/banks' . ($this->request->getPost('wizard') ? '?wizard=1' : '')))->with('success', $msg);
    }

    /** Audited reveal of the full account number or CIF (JSON). */
    public function reveal($id)
    {
        $acc   = $this->find((int) $id);
        $field = (string) $this->request->getPost('field');
        if ($field === 'cif') {
            $this->authorize('settings.bank.view_cif');
            $value = SettingsStore::decrypt($acc['cif_enc']);
        } else {
            $this->authorize('settings.bank.view_full');
            $value = SettingsStore::decrypt($acc['account_number_enc']);
        }
        service('audit')->log('bank_account.revealed', 'bank_cash_account', (int) $acc['id'], null, null, 'Revealed ' . ($field === 'cif' ? 'CIF' : 'account number'), ['field' => $field === 'cif' ? 'cif' : 'account_number']);

        return $this->json(['value' => $value ?? '']);
    }

    private function find(int $id): array
    {
        return $this->db->table('bank_cash_accounts')->where('id', $id)->get()->getRowArray() ?? $this->notFound();
    }
}
