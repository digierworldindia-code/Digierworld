<?php

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Services\Billing\DocumentNumberAllocator;

/**
 * One-time (revisitable) Billing Setup Wizard overview: status and a
 * preview of every saved identity element so nobody retypes them per invoice.
 */
class SetupWizardController extends BaseController
{
    public function index()
    {
        $c     = $this->db->table('company_settings')->where('id', 1)->get()->getRowArray() ?? [];
        $steps = json_decode((string) ($c['setup_steps'] ?? '{}'), true) ?: [];
        $fy    = service('fy')->current();
        $seqs  = $this->db->table('document_sequences')->where('financial_year_id', $fy['id'])->whereIn('doc_type', ['tax_invoice', 'cash_memo', 'receipt', 'credit_note'])->get()->getResultArray();

        return $this->render('settings/setup', [
            'pageTitle'   => 'Billing Setup Wizard',
            'breadcrumbs' => [['Settings'], ['Billing Setup Wizard']],
            'c'           => $c,
            'steps'       => $steps,
            'state'       => $c['state_code'] ? $this->db->table('states')->where('code', $c['state_code'])->get()->getRowArray() : null,
            'logo'        => $this->db->table('branding_assets')->where(['asset_type' => 'logo', 'is_active' => 1])->get()->getRowArray(),
            'signature'   => $this->db->table('branding_assets')->where(['asset_type' => 'signature', 'is_active' => 1])->get()->getRowArray(),
            'stamp'       => $this->db->table('branding_assets')->where(['asset_type' => 'stamp', 'is_active' => 1])->get()->getRowArray(),
            'banks'       => $this->db->table('bank_cash_accounts')->where('account_kind !=', 'cash')->where('is_active', 1)->orderBy('is_default_on_invoice', 'DESC')->get()->getResultArray(),
            'seqs'        => array_map(static fn ($s) => $s + ['next' => DocumentNumberAllocator::format($s, (int) $s['next_number'])], $seqs),
            'template'    => $c['default_template_id'] ? $this->db->table('document_templates')->where('id', $c['default_template_id'])->get()->getRowArray() : null,
            'reviewed'    => $this->db->table('tax_rates')->where('review_status', 'accountant_reviewed')->countAllResults(),
            'policies'    => $this->db->table('signature_policies')->countAllResults(),
            'fy'          => $fy,
        ]);
    }
}
