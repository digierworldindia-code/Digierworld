<?php

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Libraries\AmountInWords;
use App\Services\Billing\GstCalculator;
use App\Services\Billing\SnapshotBuilder;

/**
 * Invoice templates (Classic / Minimal / Premium Black & Gold / A5 memo).
 * Every save bumps the template version; finalized documents keep the
 * version and settings captured in their snapshot, so old PDFs never change.
 */
class TemplatesController extends BaseController
{
    private const POSITIONS = ['top_left' => 'Top left', 'top_center' => 'Top centre', 'top_right' => 'Top right'];

    public function index()
    {
        $rows = $this->db->table('document_templates')->orderBy('id')->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['settings'] = json_decode((string) $r['settings'], true) ?: [];
            $r['used']     = $this->db->table('billing_documents')->where('template_id', $r['id'])->where('status !=', 'draft')->countAllResults();
        }

        return $this->render('settings/templates', [
            'pageTitle' => 'Invoice templates', 'breadcrumbs' => [['Settings'], ['Invoice templates']],
            'rows' => $rows, 'positions' => self::POSITIONS,
            'default' => (int) ($this->db->table('company_settings')->select('default_template_id')->where('id', 1)->get()->getRow()->default_template_id ?? 0),
        ]);
    }

    public function update($id)
    {
        $tpl = $this->db->table('document_templates')->where('id', $this->idOr404($id))->get()->getRowArray() ?? $this->notFound();
        $in  = $this->request->getPost();
        $old = json_decode((string) $tpl['settings'], true) ?: [];
        $accent = strtoupper(trim((string) ($in['accent'] ?? '')));
        if (! preg_match('/^#[0-9A-F]{6}$/', $accent)) {
            return redirect()->back()->with('error', 'Accent colour must be a hex value such as #B08D57.');
        }
        $settings = [
            'accent'            => $accent,
            'font_size'         => max(8.0, min(11.0, (float) ($in['font_size'] ?? 9.5))),
            'margin_mm'         => max(8, min(20, (int) ($in['margin_mm'] ?? 12))),
            'logo_position'     => isset(self::POSITIONS[$in['logo_position'] ?? '']) ? $in['logo_position'] : 'top_left',
            'footer_note'       => mb_substr(trim((string) ($in['footer_note'] ?? '')), 0, 200),
        ];
        foreach (['show_logo', 'show_bank', 'show_upi_qr', 'show_terms', 'show_stamp', 'show_signature', 'show_tax_summary', 'show_amount_words'] as $flag) {
            $settings[$flag] = ! empty($in[$flag]);
        }
        $paper = ($in['paper'] ?? $tpl['paper']) === 'A5' ? 'A5' : 'A4';
        $data  = ['settings' => json_encode($settings + $old), 'paper' => $paper, 'version' => (int) $tpl['version'] + 1, 'is_active' => empty($in['is_active']) ? 0 : 1, 'updated_by' => $this->userId];

        $this->db->transBegin();
        try {
            $this->db->table('document_templates')->where('id', $tpl['id'])->update($data);
            if (! empty($in['make_default'])) {
                $this->db->table('document_templates')->update(['is_default' => 0]);
                $this->db->table('document_templates')->where('id', $tpl['id'])->update(['is_default' => 1, 'is_active' => 1]);
                $this->db->table('company_settings')->where('id', 1)->update(['default_template_id' => $tpl['id']]);
            }
            service('audit')->log('template.updated', 'document_template', (int) $tpl['id'], ['version' => $tpl['version'], 'settings' => $old], ['version' => $data['version'], 'settings' => $settings, 'paper' => $paper], 'Template ' . $tpl['name'] . ' updated to v' . $data['version']);
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
        service('settingsStore')->markSetupStep('templates');

        return redirect()->to(site_url('settings/templates'))->with('success', $tpl['name'] . ' saved as version ' . $data['version'] . '. Already-finalized documents keep the version they were issued with.');
    }

    /** Sample PDF using the company's real identity and clearly fake sample lines. */
    public function preview($id)
    {
        $tpl     = $this->db->table('document_templates')->where('id', $this->idOr404($id))->get()->getRowArray() ?? $this->notFound();
        $company = $this->db->table('company_settings')->where('id', 1)->get()->getRowArray() ?? [];
        $state   = $company['state_code'] ?? '08';
        $type    = (string) ($this->request->getGet('gst') ?: 'cgst_sgst');
        $type    = in_array($type, ['cgst_sgst', 'igst'], true) ? $type : 'cgst_sgst';
        $lines   = [
            ['item_name' => 'SAMPLE — SEO monthly retainer', 'description' => 'Preview line, not a real invoice', 'sac_code' => '998361', 'qty' => '1', 'unit' => 'month', 'unit_rate' => '25000', 'tax_treatment' => 'regular', 'gst_rate' => '18'],
            ['item_name' => 'SAMPLE — Social media creatives', 'sac_code' => '998361', 'qty' => '12', 'unit' => 'nos', 'unit_rate' => '1500', 'tax_treatment' => 'regular', 'gst_rate' => '18'],
        ];
        $treat = array_column($this->db->table('tax_treatments')->get()->getResultArray(), 'charges_tax', 'code');
        $calc  = GstCalculator::compute(['gst_type' => $type, 'price_mode' => 'exclusive', 'rounding' => $company['rounding_policy'] ?? 'nearest_rupee'], $lines, array_map('boolval', $treat));
        foreach ($calc['lines'] as $i => &$l) {
            $l['line_no'] = $i + 1;
        }
        unset($l);
        $bank = $this->db->table('bank_cash_accounts')->select('id')->where(['show_on_invoice' => 1, 'is_default_on_invoice' => 1, 'is_active' => 1])->get()->getRow()->id ?? null;
        $doc  = $calc['totals'] + [
            'doc_type' => 'tax_invoice', 'document_number' => 'SAMPLE-001', 'draft_ref' => 'SAMPLE', 'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+15 days')),
            'contact_id' => 0, 'bill_to_name' => 'Sample Customer (preview)', 'bill_to_gstin' => null, 'bill_to_address' => "Sample address line\nCity 000000",
            'bill_to_state_code' => $type === 'igst' ? ($state === '07' ? '08' : '07') : $state, 'bill_to_phone' => null, 'bill_to_email' => null, 'customer_type' => 'b2c',
            'place_of_supply_state_code' => $type === 'igst' ? ($state === '07' ? '08' : '07') : $state, 'gst_type' => $type, 'reverse_charge' => 0, 'export_type' => 'none',
            'is_sez_recipient' => 0, 'price_mode' => 'exclusive', 'notes' => 'This is a template preview with sample data.', 'terms' => $company['invoice_terms'] ?? null,
            'template_id' => $tpl['id'], 'bank_account_id' => $bank, 'amount_in_words' => AmountInWords::inr($calc['totals']['grand_total']), 'original_document_id' => null,
        ];
        $snap = (new SnapshotBuilder($this->db))->build($doc, $calc['lines'], $calc['summary'], (bool) $this->request->getGet('signed'));
        $pdf  = service('documentPdf')->renderSnapshot($snap, 'draft', 'original', []);

        return $this->response->setHeader('Content-Type', 'application/pdf')->setHeader('Content-Disposition', 'inline; filename="Template_preview_' . $tpl['code'] . '.pdf"')
            ->setHeader('Cache-Control', 'private, no-store')->setBody($pdf);
    }
}
