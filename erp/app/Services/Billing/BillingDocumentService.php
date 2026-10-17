<?php

namespace App\Services\Billing;

use App\Exceptions\AccessDeniedException;
use App\Exceptions\BusinessRuleException;
use App\Libraries\AmountInWords;
use App\Libraries\Dec;
use App\Services\Contacts\ContactService;
use App\Services\Finance\LedgerService;
use CodeIgniter\Database\BaseConnection;
use Config\Billing;

/**
 * Tax invoices, bills of supply, cash memos, estimates/quotations/proforma
 * and credit/debit notes.
 *
 * Drafts: freely editable (optimistic lock), internal DRAFT ref, no number.
 * Finalize: row lock → re-compute on the server → tax gate → statutory
 * number → immutable snapshot → ledger posting → (optional) signature.
 * Finalized documents are never edited or deleted: corrections are
 * credit/debit notes or an audited cancellation (journal reversal).
 */
class BillingDocumentService
{
    public const NON_POSTING = ['estimate', 'quotation', 'proforma'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly LedgerService $ledger,
        private readonly DocumentNumberAllocator $numbers,
    ) {
    }

    private function config(): Billing
    {
        return config(Billing::class);
    }

    private function access()
    {
        return service('billingAccess');
    }

    // ------------------------------------------------------------------
    // Computation (shared by live preview, draft save and finalization)
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed>       $h     header input
     * @param list<array<string, mixed>> $lines line input
     *
     * @return array{lines: list<array<string, mixed>>, totals: array<string, string>, summary: list<array<string, string>>, advice: array<string, mixed>, customer: array<string, mixed>|null}
     */
    public function compute(array $h, array $lines): array
    {
        $company    = $this->db->table('company_settings')->where('id', 1)->get()->getRowArray() ?? [];
        $treatRows  = $this->db->table('tax_treatments')->where('is_enabled', 1)->get()->getResultArray();
        $treatments = [];
        foreach ($treatRows as $t) {
            $treatments[$t['code']] = ['charges_tax' => (bool) $t['charges_tax'], 'requires_reason' => (bool) $t['requires_reason'], 'label' => $t['label']];
        }
        $docType   = (string) ($h['doc_type'] ?? 'tax_invoice');
        $isTaxable = (bool) ($this->config()->docTypes[$docType]['taxable'] ?? true);
        if (! $isTaxable) {
            $h['gst_type'] = 'none';
        }

        $calc = GstCalculator::compute([
            'gst_type'   => $h['gst_type'] ?? null,
            'price_mode' => $h['price_mode'] ?? ($company['default_price_mode'] ?? 'exclusive'),
            'rounding'   => $company['rounding_policy'] ?? 'nearest_rupee',
        ], $lines, array_map(static fn ($t) => $t['charges_tax'], $treatments));

        $customer = ! empty($h['contact_id']) ? (new ContactService($this->db))->billingView((int) $h['contact_id']) : null;
        $states   = [];
        foreach ($this->db->table('states')->get()->getResultArray() as $s) {
            $states[$s['code']] = ['name' => $s['name'], 'uses_utgst' => (bool) $s['uses_utgst'], 'is_special' => (bool) $s['is_special']];
        }
        $posRules = [];
        $svcIds   = array_filter(array_map(static fn ($l) => (int) ($l['service_id'] ?? 0), $lines));
        if ($svcIds !== []) {
            $posRules = array_column($this->db->table('services')->select('id, pos_rule')->whereIn('id', $svcIds)->get()->getResultArray(), 'pos_rule', 'id');
        }
        $issue = (string) ($h['issue_date'] ?? date('Y-m-d'));
        $lutValid = ! empty($company['lut_number']) && ! empty($company['lut_valid_till']) && $company['lut_valid_till'] >= $issue;

        $advice = PlaceOfSupplyAdvisor::advise([
            'doc_type'              => $docType,
            'supplier_state'        => $company['state_code'] ?? null,
            'supplier_registration' => $company['gst_registration_type'] ?? 'regular',
            'supplier_has_gstin'    => ! empty($company['gstin']),
            'customer_gstin'        => $customer['gstin'] ?? null,
            'customer_registration' => $customer['gst_registration_type'] ?? 'unregistered',
            'customer_state'        => $customer['billing_state_code'] ?? null,
            'pos_state'             => ($h['place_of_supply_state_code'] ?? '') ?: null,
            'gst_type'              => ($h['gst_type'] ?? '') ?: null,
            'is_sez'                => ! empty($h['is_sez_recipient']) || ($customer['gst_registration_type'] ?? '') === 'sez',
            'export_type'           => (string) ($h['export_type'] ?? 'none'),
            'reverse_charge'        => ! empty($h['reverse_charge']),
            'lines'                 => array_map(static fn ($l) => [
                'tax_treatment' => (string) ($l['tax_treatment'] ?? 'regular'),
                'gst_rate'      => (string) ($l['gst_rate'] ?? '0'),
                'pos_rule'      => $posRules[(int) ($l['service_id'] ?? 0)] ?? 'default',
            ], $lines),
            'treatments'            => $treatments,
            'states'                => $states,
            'lut_valid'             => $lutValid,
            'einvoice_applicable'   => ! empty($company['einvoice_applicable']),
            'taxable_doc'           => $isTaxable,
        ]);

        // Rule 46: SAC on statutory documents (mandatory for B2B; 4+ digits).
        if (! empty($this->config()->docTypes[$docType]['statutory'])) {
            $b2b = ! empty($customer['gstin']);
            foreach (array_values($lines) as $i => $l) {
                if (strlen(preg_replace('/\D/', '', (string) ($l['sac_code'] ?? ''))) < 4) {
                    $advice['messages'][] = $b2b
                        ? ['level' => 'block', 'text' => 'Line ' . ($i + 1) . ': enter the SAC code (at least 4 digits). It is mandatory on documents to registered customers.']
                        : ['level' => 'info', 'text' => 'Line ' . ($i + 1) . ': no SAC code. Recommended on every line; mandatory for B2B supplies.'];
                }
            }
            if (in_array('block', array_column($advice['messages'], 'level'), true)) {
                $advice['status'] = 'block';
            }
        }

        return ['lines' => $calc['lines'], 'totals' => $calc['totals'], 'summary' => $calc['summary'], 'advice' => $advice, 'customer' => $customer];
    }

    // ------------------------------------------------------------------
    // Drafts
    // ------------------------------------------------------------------

    /**
     * Create or update a draft. Returns document id.
     *
     * @param array<string, mixed> $in
     */
    public function saveDraft(array $in, ?int $id = null): int
    {
        $uid     = (int) service('authz')->currentUserId();
        $before  = null;
        $docType = (string) ($in['doc_type'] ?? 'tax_invoice');
        if (! isset($this->config()->docTypes[$docType])) {
            throw new BusinessRuleException('Unknown document type.');
        }
        if ($id !== null) {
            $before = $this->find($id);
            if ($before['status'] !== 'draft') {
                throw new BusinessRuleException('Only drafts can be edited. Finalized documents are corrected with a credit/debit note.');
            }
            $docType = $before['doc_type'];
            if (! $this->access()->canView($before) || ! $this->access()->can('edit_draft', $docType)) {
                throw new AccessDeniedException();
            }
            if (isset($in['lock_version']) && (int) $in['lock_version'] !== (int) $before['lock_version']) {
                throw new BusinessRuleException('This draft was changed by someone else since you opened it. Reload to see the latest version before saving.', ['lock_version' => 'stale']);
            }
        } elseif (! $this->access()->can('create_draft', $docType)) {
            throw new AccessDeniedException();
        }

        $contactId = (int) ($in['contact_id'] ?? 0);
        $contact   = $contactId > 0 ? (new ContactService($this->db))->billingView($contactId) : null;
        $errors    = [];
        if ($contact === null) {
            $errors['contact_id'] = 'Select a customer.';
        }
        $issue = ymd((string) ($in['issue_date'] ?? '')) ?? date('Y-m-d');
        $lines = $this->cleanLines((array) ($in['lines'] ?? []), $issue, $errors);
        if (in_array($docType, ['credit_note', 'debit_note'], true)) {
            $orig = (int) ($in['original_document_id'] ?? ($before['original_document_id'] ?? 0));
            if ($orig <= 0) {
                $errors['original_document_id'] = 'Choose the original invoice this note adjusts.';
            }
        }
        if ($errors !== []) {
            throw new BusinessRuleException('Please correct the highlighted fields.', $errors);
        }

        $company = $this->db->table('company_settings')->where('id', 1)->get()->getRowArray() ?? [];
        $header  = $this->header($in, $docType, $contact, $issue, $company, $before);
        $calc    = $this->compute($header + ['contact_id' => $contactId], $lines);
        $status  = ['ok' => 'auto_ok', 'review' => 'needs_review', 'block' => 'blocked'][$calc['advice']['status']];

        $data = $header + $calc['totals'] + [
            'contact_id'        => $contactId,
            'tax_review_status' => $status,
            'tax_review_reason' => null,
            'tax_reviewed_by'   => null,
            'tax_reviewed_at'   => null,
            'tax_advice'        => json_encode($calc['advice'], JSON_UNESCAPED_UNICODE),
            'amount_in_words'   => AmountInWords::inr($calc['totals']['grand_total']),
            'balance_due'       => '0.00',
            'payment_status'    => 'na',
            'updated_by'        => $uid,
        ];

        $this->db->transBegin();
        try {
            if ($before === null) {
                $data += ['doc_type' => $docType, 'status' => 'draft', 'draft_ref' => 'TMP-' . bin2hex(random_bytes(6)), 'created_by' => $uid, 'lock_version' => 1];
                $this->db->table('billing_documents')->insert($data);
                $id = (int) $this->db->insertID();
                $this->db->table('billing_documents')->where('id', $id)->update(['draft_ref' => 'DRAFT-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT)]);
            } else {
                $data['lock_version'] = (int) $before['lock_version'] + 1;
                $this->db->table('billing_documents')->where('id', $id)->update($data);
                $this->db->table('billing_line_items')->where('document_id', $id)->delete();
                $this->db->table('billing_taxes')->where('document_id', $id)->delete();
            }
            $this->storeLines((int) $id, $calc['lines'], $calc['summary'], $uid);
            service('audit')->log($before ? 'billing.draft_updated' : 'billing.draft_created', 'billing_document', $id,
                $before ? array_intersect_key($before, array_flip(['contact_id', 'grand_total', 'gst_type', 'place_of_supply_state_code'])) : null,
                ['doc_type' => $docType, 'contact_id' => $contactId, 'grand_total' => $calc['totals']['grand_total'], 'gst_type' => $header['gst_type'], 'pos' => $header['place_of_supply_state_code']],
                doc_type_label($docType) . ' draft ' . ($before ? 'updated' : 'created'));
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        return (int) $id;
    }

    /**
     * @param array<string, mixed>      $in
     * @param array<string, mixed>|null $contact
     * @param array<string, mixed>      $company
     * @param array<string, mixed>|null $before
     *
     * @return array<string, mixed>
     */
    private function header(array $in, string $docType, ?array $contact, string $issue, array $company, ?array $before): array
    {
        $authz = service('authz');
        $days  = $contact['credit_days'] ?? null;
        $days  = $days === null || $days === '' ? (int) ($company['default_payment_terms_days'] ?? 15) : (int) $days;
        $due   = ymd((string) ($in['due_date'] ?? '')) ?? (in_array($docType, self::NON_POSTING, true) ? null : date('Y-m-d', strtotime($issue . " +{$days} days")));
        $gstType = in_array($in['gst_type'] ?? null, ['cgst_sgst', 'cgst_utgst', 'igst', 'none'], true) ? $in['gst_type'] : null;

        // Bank/template: default unless the user may change them.
        $defaultBank = $this->db->table('bank_cash_accounts')->select('id')->where(['is_default_on_invoice' => 1, 'is_active' => 1])->get()->getRowArray()['id'] ?? null;
        $bankId      = $before['bank_account_id'] ?? $defaultBank;
        $templateId  = $before['template_id'] ?? ($company['default_template_id'] ?? null);
        if ($authz->can('billing.change_bank_template')) {
            if (array_key_exists('bank_account_id', $in)) {
                $bankId = $in['bank_account_id'] !== '' && $in['bank_account_id'] !== null ? (int) $in['bank_account_id'] : null;
            }
            if (! empty($in['template_id'])) {
                $templateId = (int) $in['template_id'];
            }
        }

        // Bill-to identity comes from the contact master; only users with full
        // contact rights may override it on a specific document.
        $billTo = [
            'bill_to_name'       => $contact['legal_name'] ?: ($contact['name'] ?? ''),
            'bill_to_gstin'      => $contact['gstin'] ?? null,
            'bill_to_address'    => $contact['billing_address'] ?? null,
            'bill_to_state_code' => $contact['billing_state_code'] ?? null,
            'bill_to_phone'      => $contact['phone'] ?? null,
            'bill_to_email'      => $contact['email'] ?? null,
            'customer_type'      => $contact['customer_type'] ?? 'b2c',
        ];
        if (! empty($in['bill_to_override']) && $authz->can('contacts.edit')) {
            foreach (['bill_to_name', 'bill_to_address', 'bill_to_phone', 'bill_to_email'] as $k) {
                if (isset($in[$k]) && trim((string) $in[$k]) !== '') {
                    $billTo[$k] = trim((string) $in[$k]);
                }
            }
        }

        return $billTo + [
            'issue_date'                 => $issue,
            'supply_date'                => ymd((string) ($in['supply_date'] ?? '')),
            'service_period_from'        => ymd((string) ($in['service_period_from'] ?? '')),
            'service_period_to'          => ymd((string) ($in['service_period_to'] ?? '')),
            'due_date'                   => $due,
            'reference_no'               => mb_substr(trim((string) ($in['reference_no'] ?? '')), 0, 60) ?: null,
            'po_number'                  => mb_substr(trim((string) ($in['po_number'] ?? '')), 0, 60) ?: null,
            'supplier_gstin'             => $company['gstin'] ?? null,
            'supplier_state_code'        => $company['state_code'] ?? null,
            'place_of_supply_state_code' => ($in['place_of_supply_state_code'] ?? '') ?: null,
            'is_sez_recipient'           => ! empty($in['is_sez_recipient']) ? 1 : 0,
            'export_type'                => in_array($in['export_type'] ?? 'none', ['none', 'with_payment', 'lut'], true) ? ($in['export_type'] ?? 'none') : 'none',
            'gst_type'                   => $gstType,
            'reverse_charge'             => ! empty($in['reverse_charge']) ? 1 : 0,
            'price_mode'                 => ($in['price_mode'] ?? ($company['default_price_mode'] ?? 'exclusive')) === 'inclusive' ? 'inclusive' : 'exclusive',
            'notes'                      => trim((string) ($in['notes'] ?? '')) ?: null,
            'terms'                      => trim((string) ($in['terms'] ?? '')) ?: ($before['terms'] ?? ($company['invoice_terms'] ?? null)),
            'internal_notes'             => trim((string) ($in['internal_notes'] ?? '')) ?: null,
            'bank_account_id'            => $bankId,
            'template_id'                => $templateId,
            'original_document_id'       => ! empty($in['original_document_id']) ? (int) $in['original_document_id'] : ($before['original_document_id'] ?? null),
            'lead_id'                    => ! empty($in['lead_id']) ? (int) $in['lead_id'] : ($before['lead_id'] ?? null),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $raw
     * @param array<string, string>            $errors
     *
     * @return list<array<string, mixed>>
     */
    private function cleanLines(array $raw, string $issue, array &$errors): array
    {
        $treatments = array_column($this->db->table('tax_treatments')->where('is_enabled', 1)->get()->getResultArray(), 'charges_tax', 'code');
        $rates      = $this->db->table('tax_rates')->where('is_active', 1)->where('effective_from <=', $issue)
            ->groupStart()->where('effective_to', null)->orWhere('effective_to >=', $issue)->groupEnd()->get()->getResultArray();
        $validRates = array_map(static fn ($r) => Dec::round($r['rate'], 3), $rates);
        $out = [];
        foreach (array_values($raw) as $i => $l) {
            $name = trim((string) ($l['item_name'] ?? ''));
            $desc = trim((string) ($l['description'] ?? ''));
            if ($name === '' && $desc === '' && empty($l['service_id']) && (float) ($l['unit_rate'] ?? 0) == 0.0) {
                continue; // blank row
            }
            $n = count($out) + 1;
            if ($name === '') {
                $errors["lines.{$i}.item_name"] = "Line {$n}: item/service name is required.";
            }
            $qty = (string) ($l['qty'] ?? '1');
            if (! is_numeric($qty) || (float) $qty <= 0) {
                $errors["lines.{$i}.qty"] = "Line {$n}: quantity must be more than 0.";
                $qty = '1';
            }
            $rate = str_replace(',', '', (string) ($l['unit_rate'] ?? '0'));
            if (! is_numeric($rate) || (float) $rate < 0) {
                $errors["lines.{$i}.unit_rate"] = "Line {$n}: rate must be 0 or more.";
                $rate = '0';
            }
            $treat = (string) ($l['tax_treatment'] ?? 'regular');
            if (! array_key_exists($treat, $treatments)) {
                $errors["lines.{$i}.tax_treatment"] = "Line {$n}: tax treatment is not enabled.";
                $treat = 'regular';
            }
            $gst = Dec::round((string) ($l['gst_rate'] ?? '0'), 3);
            if ((int) $treatments[$treat] === 1 && ! in_array($gst, $validRates, true)) {
                $errors["lines.{$i}.gst_rate"] = "Line {$n}: GST rate {$gst}% is not an active rate on " . dmy($issue) . '.';
            }
            $sac = preg_replace('/\D/', '', (string) ($l['sac_code'] ?? ''));
            $out[] = [
                'service_id'     => ! empty($l['service_id']) ? (int) $l['service_id'] : null,
                'item_name'      => mb_substr($name, 0, 200),
                'description'    => $desc !== '' ? $desc : null,
                'sac_code'       => $sac !== '' ? substr($sac, 0, 8) : null,
                'qty'            => $qty,
                'unit'           => mb_substr(trim((string) ($l['unit'] ?? '')), 0, 20) ?: null,
                'unit_rate'      => $rate,
                'discount_type'  => ($l['discount_type'] ?? 'amount') === 'percent' ? 'percent' : 'amount',
                'discount_value' => is_numeric($l['discount_value'] ?? null) ? (string) $l['discount_value'] : '0',
                'tax_treatment'  => $treat,
                'gst_rate'       => $gst,
                'cess_rate'      => is_numeric($l['cess_rate'] ?? null) ? (string) $l['cess_rate'] : '0',
            ];
        }
        if ($out === []) {
            $errors['lines'] = 'Add at least one line item.';
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $lines @param list<array<string, string>> $summary */
    private function storeLines(int $docId, array $lines, array $summary, int $uid): void
    {
        foreach ($lines as $l) {
            unset($l['charges_tax'], $l['id']);
            $this->db->table('billing_line_items')->insert($l + ['document_id' => $docId, 'created_by' => $uid]);
        }
        foreach ($summary as $s) {
            $this->db->table('billing_taxes')->insert($s + ['document_id' => $docId]);
        }
    }

    // ------------------------------------------------------------------
    // Finalization
    // ------------------------------------------------------------------

    /**
     * @param array{apply_signature?: bool, tax_review_reason?: string, paid_at_counter_account?: int|null, paid_mode?: string} $opts
     *
     * @return array{document_number: string, receipt_id: int|null}
     */
    public function finalize(int $id, array $opts = []): array
    {
        $authz = service('authz');
        $uid   = (int) $authz->currentUserId();

        $this->db->transBegin();
        try {
            $doc = $this->db->query('SELECT * FROM billing_documents WHERE id = ? FOR UPDATE', [$id])->getRowArray() ?? throw new BusinessRuleException('Document not found.');
            if (! $this->access()->canView($doc) || ! $this->access()->can('finalize', $doc['doc_type'])) {
                throw new AccessDeniedException();
            }
            if ($doc['status'] !== 'draft') {
                throw new BusinessRuleException('This document is already ' . $doc['status'] . '.');
            }
            $company = $this->db->table('company_settings')->where('id', 1)->get()->getRowArray() ?? [];
            $isSale  = ! in_array($doc['doc_type'], self::NON_POSTING, true);
            if ($isSale && (empty($company['legal_name']) || empty($company['state_code']))) {
                throw new BusinessRuleException('Complete the legal business profile (legal name and state) in the Billing Setup Wizard before issuing statutory documents.');
            }

            // Re-compute from stored lines: the server is the source of truth.
            $lines = $this->db->table('billing_line_items')->where('document_id', $id)->orderBy('line_no')->get()->getResultArray();
            $calc  = $this->compute($doc, $lines);
            $advice = $calc['advice'];
            if ($advice['status'] === 'block') {
                $msgs = array_column(array_filter($advice['messages'], static fn ($m) => $m['level'] === 'block'), 'text');

                throw new BusinessRuleException('Cannot finalize: ' . implode(' ', $msgs));
            }
            $reviewStatus = 'auto_ok';
            $reason       = trim((string) ($opts['tax_review_reason'] ?? ''));
            if ($advice['status'] === 'review') {
                if (! $authz->can('billing.tax.override')) {
                    throw new BusinessRuleException('This document needs a tax review (see GST panel). Ask a user with "Resolve tax review" permission to finalize it.');
                }
                if (mb_strlen($reason) < 10) {
                    throw new BusinessRuleException('Record the reason for the tax/place-of-supply decision (at least 10 characters) to finalize.', ['tax_review_reason' => 'required']);
                }
                $reviewStatus = 'resolved';
            }

            // Credit/debit note integrity
            $original = null;
            if (in_array($doc['doc_type'], ['credit_note', 'debit_note'], true)) {
                $original = $this->db->query('SELECT * FROM billing_documents WHERE id = ? FOR UPDATE', [(int) $doc['original_document_id']])->getRowArray();
                if ($original === null || $original['status'] !== 'finalized' || ! in_array($original['doc_type'], ['tax_invoice', 'cash_memo', 'bill_of_supply'], true)) {
                    throw new BusinessRuleException('A note must reference a finalized tax invoice, cash memo or bill of supply.');
                }
                if ((int) $original['contact_id'] !== (int) $doc['contact_id']) {
                    throw new BusinessRuleException('The note must be issued to the same customer as the original invoice.');
                }
                if ($original['gst_type'] !== $doc['gst_type'] || $original['place_of_supply_state_code'] !== $doc['place_of_supply_state_code']) {
                    throw new BusinessRuleException('The note must use the same GST type and place of supply as the original invoice (' . gst_type_label($original['gst_type']) . ', POS ' . $original['place_of_supply_state_code'] . ').');
                }
                if ($doc['doc_type'] === 'credit_note') {
                    $already = $this->db->query("SELECT COALESCE(SUM(d.grand_total),0) s FROM billing_documents d WHERE d.original_document_id = ? AND d.doc_type = 'credit_note' AND d.status = 'finalized'", [$original['id']])->getRow()->s;
                    $room    = Dec::sub($original['grand_total'], $already);
                    if (Dec::cmp($calc['totals']['grand_total'], $room) > 0) {
                        throw new BusinessRuleException('Credit note total ' . inr($calc['totals']['grand_total']) . ' exceeds what remains creditable on ' . $original['document_number'] . ' (' . inr($room) . ').');
                    }
                }
            }

            // Signature authorisation (owner-approved policy + permission)
            $signed = false;
            if (! empty($opts['apply_signature'])) {
                if (! $this->canSign($doc['doc_type'], $uid)) {
                    throw new AccessDeniedException('You are not authorised to apply the company signature/stamp on this document type.');
                }
                $signed = true;
            }

            // Number allocation (row-locked; rolls back with the transaction)
            $seriesKey = $this->config()->docTypes[$doc['doc_type']]['series'];
            $num       = $this->numbers->allocate($seriesKey, $doc['issue_date']);

            $update = $calc['totals'] + [
                'status'             => 'finalized',
                'sequence_id'        => $num['sequence_id'],
                'sequence_number'    => $num['number'],
                'document_number'    => $num['formatted'],
                'financial_year_id'  => $num['financial_year_id'],
                'tax_review_status'  => $reviewStatus,
                'tax_review_reason'  => $reviewStatus === 'resolved' ? $reason : null,
                'tax_reviewed_by'    => $reviewStatus === 'resolved' ? $uid : null,
                'tax_reviewed_at'    => $reviewStatus === 'resolved' ? date('Y-m-d H:i:s') : null,
                'tax_advice'         => json_encode($advice, JSON_UNESCAPED_UNICODE),
                'amount_in_words'    => AmountInWords::inr($calc['totals']['grand_total']),
                'apply_signature'    => $signed ? 1 : 0,
                'signed_by'          => $signed ? $uid : null,
                'signed_at'          => $signed ? date('Y-m-d H:i:s') : null,
                'finalized_by'       => $uid,
                'finalized_at'       => date('Y-m-d H:i:s'),
                'payment_status'     => in_array($doc['doc_type'], ['tax_invoice', 'bill_of_supply', 'cash_memo', 'debit_note'], true) ? 'unpaid' : 'na',
                'balance_due'        => in_array($doc['doc_type'], ['tax_invoice', 'bill_of_supply', 'cash_memo', 'debit_note'], true) ? $calc['totals']['grand_total'] : '0.00',
                'lock_version'       => (int) $doc['lock_version'] + 1,
            ];
            // refresh lines/taxes with the authoritative computation
            $this->db->table('billing_line_items')->where('document_id', $id)->delete();
            $this->db->table('billing_taxes')->where('document_id', $id)->delete();
            $this->storeLines($id, $calc['lines'], $calc['summary'], (int) $doc['created_by']);
            $this->db->table('billing_documents')->where('id', $id)->update($update);

            $final = $this->find($id);
            $snapB = new SnapshotBuilder($this->db);
            $snap  = $snapB->build($final, $calc['lines'], $calc['summary'], $signed);
            $snapB->store($id, $snap);
            if ($signed) {
                $this->db->table('signature_usage_log')->insert([
                    'document_id' => $id, 'user_id' => $uid,
                    'assets'      => json_encode(array_filter(array_intersect_key($snap['branding'], array_flip(['signature', 'stamp', 'sign_stamp'])))),
                ]);
            }

            if ($isSale) {
                $jeId = $this->postSale($final, $calc['lines']);
                $this->db->table('billing_documents')->where('id', $id)->update(['journal_entry_id' => $jeId]);
            }
            if ($original !== null) {
                $this->db->table('credit_debit_note_links')->insert([
                    'note_document_id' => $id, 'original_document_id' => $original['id'],
                    'reason_code'      => in_array($opts['reason_code'] ?? '', ['price_revision', 'service_deficiency', 'post_sale_discount', 'excess_billing', 'short_billing', 'cancellation', 'other'], true) ? $opts['reason_code'] : 'other',
                    'reason_text'      => mb_substr((string) ($opts['reason_text'] ?? ($doc['notes'] ?? 'Adjustment')), 0, 500) ?: 'Adjustment',
                ]);
                $this->recalcPayment((int) $original['id']);
            }
            if (in_array($doc['doc_type'], ['tax_invoice', 'cash_memo', 'debit_note', 'bill_of_supply'], true) && ! empty($doc['converted_from_id'])) {
                $this->db->table('billing_documents')->where('id', $doc['converted_from_id'])->update(['status' => 'converted']);
            }

            $receiptId = null;
            if (! empty($opts['paid_at_counter_account']) && in_array($doc['doc_type'], ['cash_memo', 'tax_invoice', 'bill_of_supply'], true)) {
                if (! $authz->can('finance.receipt.create')) {
                    throw new AccessDeniedException('Recording the counter payment needs the "Record receipts" permission.');
                }
                $receiptId = service('receipts')->create([
                    'contact_id'           => (int) $doc['contact_id'],
                    'receipt_date'         => $doc['issue_date'],
                    'amount'               => $calc['totals']['grand_total'],
                    'mode'                 => $opts['paid_mode'] ?? 'cash',
                    'bank_cash_account_id' => (int) $opts['paid_at_counter_account'],
                    'notes'                => 'Paid at counter against ' . $num['formatted'],
                    'allocations'          => [['document_id' => $id, 'amount' => $calc['totals']['grand_total']]],
                ], true);
            }

            service('audit')->log('billing.finalized', 'billing_document', $id, ['status' => 'draft', 'draft_ref' => $doc['draft_ref']], [
                'document_number' => $num['formatted'], 'grand_total' => $calc['totals']['grand_total'], 'gst_type' => $doc['gst_type'],
                'tax_review' => $reviewStatus, 'signed' => $signed, 'template_version' => $snap['template']['version'],
            ], doc_type_label($doc['doc_type']) . ' ' . $num['formatted'] . ' finalized');
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        return ['document_number' => $num['formatted'], 'receipt_id' => $receiptId];
    }

    public function canSign(string $docType, int $userId): bool
    {
        $authz = service('authz');
        if (! $authz->can('billing.apply_signature', $userId)) {
            return false;
        }
        $hasAsset = $this->db->table('branding_assets')->whereIn('asset_type', ['signature', 'sign_stamp'])->where('is_active', 1)->countAllResults() > 0;
        if (! $hasAsset) {
            return false;
        }
        $roleIds = array_column($this->db->table('user_roles')->select('role_id')->where('user_id', $userId)
            ->groupStart()->where('expires_at', null)->orWhere('expires_at >', date('Y-m-d H:i:s'))->groupEnd()->get()->getResultArray(), 'role_id');
        $b = $this->db->table('signature_policies')->where(['doc_type' => $docType, 'allowed' => 1])->where('approved_by IS NOT NULL', null, false)
            ->groupStart()->where('user_id', $userId);
        if ($roleIds !== []) {
            $b->orWhereIn('role_id', $roleIds);
        }

        return $b->groupEnd()->countAllResults() > 0;
    }

    /**
     * Sale: Dr Receivable / Cr Sales (by treatment) + Output taxes ± round-off.
     * Credit note mirrors it. Never touches cash — that is the receipt's job.
     *
     * @param array<string, mixed>       $doc
     * @param list<array<string, mixed>> $lines
     */
    private function postSale(array $doc, array $lines): int
    {
        $sign   = (int) $this->config()->docTypes[$doc['doc_type']]['sign'];
        $cid    = (int) $doc['contact_id'];
        $l      = $this->ledger;
        $income = ['sales' => '0', 'sales_exempt' => '0', 'sales_zero_rated' => '0'];
        foreach ($lines as $line) {
            $bucket = match ($line['tax_treatment']) {
                'exempt', 'nil_rated', 'non_gst' => 'sales_exempt',
                'zero_rated_lut', 'zero_rated_igst' => 'sales_zero_rated',
                default => 'sales',
            };
            $income[$bucket] = Dec::add($income[$bucket], $line['taxable_value']);
        }
        $entries = [];
        $add = static function (int $acc, string $amount, bool $debitSide, ?int $contact = null, ?string $memo = null) use (&$entries, $sign): void {
            if (Dec::isZero($amount)) {
                return;
            }
            // credit note (sign −1) swaps sides
            $isDebit = $sign >= 0 ? $debitSide : ! $debitSide;
            $entries[] = ['account' => $acc, $isDebit ? 'debit' : 'credit' => $amount, 'contact_id' => $contact, 'memo' => $memo];
        };
        $add($l->account('receivable'), $doc['grand_total'], true, $cid, doc_type_label($doc['doc_type']) . ' ' . $doc['document_number']);
        foreach ($income as $sub => $amt) {
            $acc = $sign < 0 ? $l->account('sales_returns') : $l->account($sub);
            $add($acc, $amt, false, $cid);
        }
        foreach (['cgst', 'sgst', 'utgst', 'igst', 'cess'] as $t) {
            $add($l->account('output_' . $t), $doc[$t . '_total'], false, $cid);
        }
        if (! Dec::isZero($doc['round_off'])) {
            // positive round-off increases the receivable → credit round-off; negative → debit
            Dec::isPositive($doc['round_off'])
                ? $add($l->account('round_off'), $doc['round_off'], false)
                : $add($l->account('round_off'), Dec::abs($doc['round_off']), true);
        }

        return $l->post($doc['issue_date'], 'billing_document', (int) $doc['id'], doc_type_label($doc['doc_type']) . ' ' . $doc['document_number'] . ' — ' . $doc['bill_to_name'], $entries, $doc['document_number']);
    }

    // ------------------------------------------------------------------
    // After finalization
    // ------------------------------------------------------------------

    /** Recompute paid / credited / balance / payment status from source records. */
    public function recalcPayment(int $docId): void
    {
        $doc = $this->find($docId);
        if ($doc['status'] !== 'finalized' || ! in_array($doc['doc_type'], ['tax_invoice', 'bill_of_supply', 'cash_memo', 'debit_note'], true)) {
            return;
        }
        $paid     = $this->db->query('SELECT COALESCE(SUM(a.amount),0) s FROM payment_allocations a JOIN payment_receipts r ON r.id = a.receipt_id WHERE a.document_id = ? AND a.reversed_at IS NULL AND r.status = ?', [$docId, 'active'])->getRow()->s;
        $credited = $this->db->query("SELECT COALESCE(SUM(grand_total),0) s FROM billing_documents WHERE original_document_id = ? AND doc_type = 'credit_note' AND status = 'finalized'", [$docId])->getRow()->s;
        $balance  = Dec::sub(Dec::sub($doc['grand_total'], $paid), $credited);
        $status   = match (true) {
            Dec::isNegative($balance)                         => 'overpaid',
            Dec::isZero($balance)                             => 'paid',
            Dec::isPositive($paid) || Dec::isPositive($credited) => 'partial',
            default                                           => 'unpaid',
        };
        $this->db->table('billing_documents')->where('id', $docId)->update([
            'amount_paid' => Dec::round($paid), 'amount_credited' => Dec::round($credited),
            'balance_due' => Dec::max($balance, '0'), 'payment_status' => $status,
        ]);
    }

    public function cancel(int $id, string $reason): void
    {
        $authz = service('authz');
        $this->db->transBegin();
        try {
            $doc = $this->db->query('SELECT * FROM billing_documents WHERE id = ? FOR UPDATE', [$id])->getRowArray() ?? throw new BusinessRuleException('Document not found.');
            if (! $this->access()->canView($doc) || ! $this->access()->can('cancel', $doc['doc_type'])) {
                throw new AccessDeniedException();
            }
            if ($doc['status'] !== 'finalized') {
                throw new BusinessRuleException('Only finalized documents can be cancelled (delete drafts instead).');
            }
            if (mb_strlen(trim($reason)) < 10) {
                throw new BusinessRuleException('Give a cancellation reason (at least 10 characters).');
            }
            $allocs = $this->db->table('payment_allocations')->where(['document_id' => $id, 'reversed_at' => null])->countAllResults();
            $notes  = $this->db->table('billing_documents')->where(['original_document_id' => $id, 'status' => 'finalized'])->countAllResults();
            if ($allocs > 0 || $notes > 0) {
                throw new BusinessRuleException('This document has payments or credit/debit notes. Void the receipts first, or issue a credit note instead of cancelling.');
            }
            if ($doc['journal_entry_id']) {
                $this->ledger->reverse((int) $doc['journal_entry_id'], date('Y-m-d') < $doc['issue_date'] ? $doc['issue_date'] : date('Y-m-d'), 'Cancellation: ' . $reason);
            }
            $this->db->table('billing_documents')->where('id', $id)->update([
                'status' => 'cancelled', 'cancel_reason' => $reason, 'cancelled_by' => $authz->currentUserId(), 'cancelled_at' => date('Y-m-d H:i:s'),
                'balance_due' => '0.00', 'payment_status' => 'na',
            ]);
            service('audit')->log('billing.cancelled', 'billing_document', $id, ['status' => 'finalized'], ['status' => 'cancelled', 'reason' => $reason], doc_type_label($doc['doc_type']) . ' ' . $doc['document_number'] . ' cancelled (number retained, never reused)');
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
    }

    public function deleteDraft(int $id): void
    {
        $doc = $this->find($id);
        if ($doc['status'] !== 'draft') {
            throw new BusinessRuleException('Finalized documents can never be deleted.');
        }
        if (! $this->access()->canView($doc) || ! $this->access()->can('edit_draft', $doc['doc_type'])) {
            throw new AccessDeniedException();
        }
        $this->db->transBegin();
        try {
            $this->db->table('billing_line_items')->where('document_id', $id)->delete();
            $this->db->table('billing_taxes')->where('document_id', $id)->delete();
            $this->db->table('billing_documents')->where('id', $id)->delete();
            service('audit')->log('billing.draft_deleted', 'billing_document', $id, ['draft_ref' => $doc['draft_ref'], 'grand_total' => $doc['grand_total']], null, 'Draft deleted');
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
    }

    /** New draft copying customer + lines (never number, status or payments). */
    public function duplicate(int $id, ?string $asType = null): int
    {
        $doc = $this->find($id);
        if (! $this->access()->canView($doc)) {
            throw new AccessDeniedException();
        }
        $type  = $asType ?? $doc['doc_type'];
        $lines = $this->db->table('billing_line_items')->where('document_id', $id)->orderBy('line_no')->get()->getResultArray();
        $in = array_intersect_key($doc, array_flip(['contact_id', 'place_of_supply_state_code', 'gst_type', 'is_sez_recipient', 'export_type', 'reverse_charge', 'price_mode', 'notes', 'terms', 'reference_no', 'po_number', 'bank_account_id', 'template_id', 'lead_id']));
        $in['doc_type']   = $type;
        $in['issue_date'] = date('Y-m-d');
        $in['lines']      = array_map(static fn ($l) => array_intersect_key($l, array_flip(['service_id', 'item_name', 'description', 'sac_code', 'qty', 'unit', 'unit_rate', 'discount_type', 'discount_value', 'tax_treatment', 'gst_rate', 'cess_rate'])), $lines);
        if (in_array($type, ['credit_note', 'debit_note'], true)) {
            $in['original_document_id'] = $id;
        }
        $newId = $this->saveDraft($in);
        $col   = in_array($doc['doc_type'], self::NON_POSTING, true) && ! in_array($type, self::NON_POSTING, true) ? 'converted_from_id' : 'duplicated_from_id';
        if (in_array($type, ['credit_note', 'debit_note'], true)) {
            $col = null;
        }
        if ($col !== null) {
            $this->db->table('billing_documents')->where('id', $newId)->update([$col => $id]);
        }

        return $newId;
    }

    /** @return array<string, mixed> */
    public function find(int $id): array
    {
        return $this->db->table('billing_documents')->where('id', $id)->get()->getRowArray() ?? throw new BusinessRuleException('Document not found.');
    }
}
