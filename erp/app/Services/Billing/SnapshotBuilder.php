<?php

namespace App\Services\Billing;

use App\Services\Settings\SettingsStore;
use CodeIgniter\Database\BaseConnection;

/**
 * Builds the immutable "as issued" snapshot of a document: supplier legal
 * identity, customer billing details, ONLY the bank fields actually printed
 * (never CIF), branding asset versions/hashes, template version, lines,
 * tax summary and totals. Finalized PDFs render exclusively from this, so
 * later changes to company settings never alter issued documents.
 */
class SnapshotBuilder
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param array<string, mixed>       $doc
     * @param list<array<string, mixed>> $lines
     * @param list<array<string, mixed>> $taxes
     *
     * @return array<string, mixed>
     */
    public function build(array $doc, array $lines, array $taxes, bool $signed = false): array
    {
        $c      = $this->db->table('company_settings')->where('id', 1)->get()->getRowArray() ?? [];
        $states = array_column($this->db->table('states')->get()->getResultArray(), 'name', 'code');

        $supplier = [
            'legal_name'   => $c['legal_name'] ?? null,
            'trade_name'   => $c['trade_name'] ?? null,
            'tagline'      => $c['tagline'] ?? null,
            'gstin'        => $c['gstin'] ?? null,
            'pan'          => $c['pan'] ?? null,
            'registration' => $c['gst_registration_type'] ?? 'regular',
            'state_code'   => $c['state_code'] ?? null,
            'state_name'   => $states[$c['state_code'] ?? ''] ?? null,
            'address'      => array_values(array_filter([
                $c['bill_address_line1'] ?? $c['reg_address_line1'] ?? null,
                $c['bill_address_line2'] ?? $c['reg_address_line2'] ?? null,
                trim(($c['bill_city'] ?? $c['reg_city'] ?? '') . ' ' . ($c['bill_pincode'] ?? $c['reg_pincode'] ?? '')),
            ])),
            'phone'        => $c['phone'] ?? null,
            'email'        => $c['email'] ?? null,
            'website'      => $c['website'] ?? null,
            'footer'       => $c['invoice_footer'] ?? null,
            'disclaimer'   => $c['legal_disclaimer'] ?? null,
            'lut_number'   => $c['lut_number'] ?? null,
            'profile_version' => (int) ($c['profile_version'] ?? 1),
        ];
        $customer = [
            'contact_id'   => (int) $doc['contact_id'],
            'name'         => $doc['bill_to_name'],
            'gstin'        => $doc['bill_to_gstin'],
            'address'      => $doc['bill_to_address'],
            'state_code'   => $doc['bill_to_state_code'],
            'state_name'   => $states[$doc['bill_to_state_code'] ?? ''] ?? null,
            'phone'        => $doc['bill_to_phone'],
            'email'        => $doc['bill_to_email'],
            'customer_type'=> $doc['customer_type'],
        ];

        $bank = null;
        if (! empty($doc['bank_account_id'])) {
            $b = $this->db->table('bank_cash_accounts')->where('id', $doc['bank_account_id'])->get()->getRowArray();
            $printable = $b !== null && (int) $b['show_on_invoice'] === 1 && $b['account_kind'] !== 'cash'
                && ($b['ownership'] !== 'owner_personal' || ! empty($b['print_approved_by']));
            if ($printable) {
                // Only printable fields. CIF is intentionally never read here.
                $bank = [
                    'account_id'       => (int) $b['id'],
                    'bank_name'        => $b['bank_name'],
                    'beneficiary_name' => $b['beneficiary_name'],
                    'account_number'   => SettingsStore::decrypt($b['account_number_enc']),
                    'ifsc'             => $b['ifsc'],
                    'branch'           => $b['branch'],
                    'account_type'     => $b['account_type'],
                    'upi_id'           => $b['upi_id'],
                ];
            }
        }

        $tpl = $this->db->table('document_templates')->where('id', $doc['template_id'] ?? 0)->get()->getRowArray()
            ?? $this->db->table('document_templates')->where('id', $c['default_template_id'] ?? 0)->get()->getRowArray()
            ?? $this->db->table('document_templates')->where('is_default', 1)->get()->getRowArray();
        $settings = json_decode((string) ($tpl['settings'] ?? '{}'), true) ?: [];

        $asset = function (string $type) use ($doc): ?array {
            $a = $this->db->table('branding_assets b')->select('b.*, f.sha256')->join('stored_files f', 'f.id = b.stored_file_id')
                ->where(['b.asset_type' => $type, 'b.is_active' => 1])->get()->getRowArray();
            if ($a === null) {
                return null;
            }
            $enabled = json_decode((string) $a['enabled_doc_types'], true) ?: [];
            if (! in_array($doc['doc_type'], $enabled, true)) {
                return null;
            }

            return ['asset_id' => (int) $a['id'], 'file_id' => (int) $a['stored_file_id'], 'version' => (int) $a['version'], 'sha256' => $a['sha256'],
                'position' => $a['position_preset'], 'width_mm' => (float) $a['display_width_mm'], 'bank_account_id' => $a['bank_account_id'] ? (int) $a['bank_account_id'] : null];
        };
        $branding = ['logo' => ($settings['show_logo'] ?? true) ? $asset('logo') : null];
        if ($signed) {
            $combined = $asset('sign_stamp');
            $branding['sign_stamp'] = $combined;
            if ($combined === null) {
                $branding['signature'] = ($settings['show_signature'] ?? true) ? $asset('signature') : null;
                $branding['stamp']     = ($settings['show_stamp'] ?? true) ? $asset('stamp') : null;
            }
        }
        if ($bank !== null && ($settings['show_upi_qr'] ?? true)) {
            $qr = $asset('upi_qr');
            $branding['upi_qr'] = $qr !== null && ($qr['bank_account_id'] === null || $qr['bank_account_id'] === $bank['account_id']) ? $qr : null;
        }

        return [
            'supplier' => $supplier,
            'customer' => $customer,
            'bank'     => $bank,
            'branding' => $branding,
            'template' => ['id' => (int) ($tpl['id'] ?? 0), 'code' => $tpl['code'] ?? 'classic', 'version' => (int) ($tpl['version'] ?? 1),
                'layout' => $tpl['layout'] ?? 'classic', 'paper' => $tpl['paper'] ?? 'A4', 'settings' => $settings],
            'lines'    => array_map(static fn ($l) => array_intersect_key($l, array_flip([
                'line_no', 'item_name', 'description', 'sac_code', 'qty', 'unit', 'unit_rate', 'discount_amount', 'gross_amount', 'taxable_value',
                'tax_treatment', 'gst_rate', 'cgst_rate', 'cgst_amount', 'sgst_rate', 'sgst_amount', 'utgst_rate', 'utgst_amount', 'igst_rate', 'igst_amount',
                'cess_rate', 'cess_amount', 'line_total',
            ])), $lines),
            'tax_summary' => array_map(static fn ($t) => array_intersect_key($t, array_flip(['tax_type', 'rate', 'taxable_value', 'tax_amount'])), $taxes),
            'totals'   => array_intersect_key($doc, array_flip([
                'subtotal', 'discount_total', 'taxable_total', 'cgst_total', 'sgst_total', 'utgst_total', 'igst_total', 'cess_total',
                'tax_total', 'round_off', 'grand_total', 'amount_in_words',
            ])),
            'header'   => array_intersect_key($doc, array_flip([
                'doc_type', 'document_number', 'draft_ref', 'issue_date', 'supply_date', 'service_period_from', 'service_period_to', 'due_date',
                'reference_no', 'po_number', 'place_of_supply_state_code', 'gst_type', 'reverse_charge', 'export_type', 'is_sez_recipient',
                'price_mode', 'notes', 'terms', 'einvoice_irn', 'einvoice_ack_no', 'einvoice_ack_date', 'original_document_id',
            ])) + ['place_of_supply_name' => $states[$doc['place_of_supply_state_code'] ?? ''] ?? null, 'signed' => $signed],
        ];
    }

    /** Persist the snapshot with a content hash. @param array<string, mixed> $snap */
    public function store(int $documentId, array $snap): int
    {
        $payload = [
            'document_id' => $documentId,
            'supplier'    => json_encode($snap['supplier'], JSON_UNESCAPED_UNICODE),
            'customer'    => json_encode($snap['customer'], JSON_UNESCAPED_UNICODE),
            'bank'        => $snap['bank'] === null ? null : json_encode($snap['bank'], JSON_UNESCAPED_UNICODE),
            'branding'    => json_encode($snap['branding'], JSON_UNESCAPED_UNICODE),
            'template'    => json_encode($snap['template'], JSON_UNESCAPED_UNICODE),
            'line_items'  => json_encode($snap['lines'], JSON_UNESCAPED_UNICODE),
            'tax_summary' => json_encode($snap['tax_summary'], JSON_UNESCAPED_UNICODE),
            'totals'      => json_encode(['totals' => $snap['totals'], 'header' => $snap['header']], JSON_UNESCAPED_UNICODE),
        ];
        $payload['content_hash'] = hash('sha256', implode('|', array_slice($payload, 1)));
        $this->db->table('billing_document_snapshots')->insert($payload);

        return (int) $this->db->insertID();
    }

    /** Load a stored snapshot back into the build() shape. @return array<string, mixed>|null */
    public function load(int $documentId): ?array
    {
        $row = $this->db->table('billing_document_snapshots')->where('document_id', $documentId)->get()->getRowArray();
        if ($row === null) {
            return null;
        }
        $t = json_decode($row['totals'], true);

        return [
            'supplier' => json_decode($row['supplier'], true), 'customer' => json_decode($row['customer'], true),
            'bank' => $row['bank'] ? json_decode($row['bank'], true) : null, 'branding' => json_decode($row['branding'], true),
            'template' => json_decode($row['template'], true), 'lines' => json_decode($row['line_items'], true),
            'tax_summary' => json_decode($row['tax_summary'], true), 'totals' => $t['totals'], 'header' => $t['header'],
            'content_hash' => $row['content_hash'], 'snapshot_at' => $row['created_at'],
        ];
    }
}
