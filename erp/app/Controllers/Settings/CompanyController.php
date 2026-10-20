<?php

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Libraries\Gstin;

/**
 * Legal business identity (Setup Wizard step 1). Every save creates a new
 * profile version; finalized documents keep their own snapshot, so editing
 * here never rewrites historical invoices.
 */
class CompanyController extends BaseController
{
    public function index()
    {
        $c = $this->db->table('company_settings')->where('id', 1)->get()->getRowArray() ?? [];

        return $this->render('settings/company', [
            'pageTitle'   => 'Company profile',
            'breadcrumbs' => [['Settings'], ['Company profile']],
            'c'           => $c,
            'states'      => $this->db->table('states')->where('is_special', 0)->orderBy('name')->get()->getResultArray(),
            'versions'    => $this->db->table('company_profile_versions v')->select('v.profile_version, v.created_at, p.full_name')
                ->join('user_profiles p', 'p.user_id = v.created_by', 'left')->orderBy('v.profile_version', 'DESC')->limit(10)->get()->getResultArray(),
            'canEdit'     => $this->can('settings.company.edit'),
            'wizard'      => $this->request->getGet('wizard') !== null,
        ]);
    }

    public function update()
    {
        $before = $this->db->table('company_settings')->where('id', 1)->get()->getRowArray() ?? [];
        $p      = fn (string $k) => $this->post($k);
        $data   = [
            'legal_name'            => $p('legal_name'),
            'trade_name'            => $p('trade_name'),
            'tagline'               => $p('tagline'),
            'brand_description'     => $p('brand_description'),
            'gst_registration_type' => in_array($p('gst_registration_type'), ['regular', 'composition', 'unregistered'], true) ? $p('gst_registration_type') : 'regular',
            'gstin'                 => Gstin::normalize($p('gstin')),
            'pan'                   => $p('pan') ? strtoupper((string) $p('pan')) : null,
            'state_code'            => $p('state_code'),
            'reg_address_line1'     => $p('reg_address_line1'),
            'reg_address_line2'     => $p('reg_address_line2'),
            'reg_city'              => $p('reg_city'),
            'reg_district'          => $p('reg_district'),
            'reg_pincode'           => $p('reg_pincode'),
            'bill_address_same'     => $this->request->getPost('bill_address_same') ? 1 : 0,
            'bill_address_line1'    => $p('bill_address_line1'),
            'bill_address_line2'    => $p('bill_address_line2'),
            'bill_city'             => $p('bill_city'),
            'bill_district'         => $p('bill_district'),
            'bill_state_code'       => $p('bill_state_code'),
            'bill_pincode'          => $p('bill_pincode'),
            'phone'                 => $p('phone'),
            'alt_phone'             => $p('alt_phone'),
            'email'                 => $p('email'),
            'alt_email'             => $p('alt_email'),
            'website'               => $p('website'),
            'business_category'     => $p('business_category'),
            'einvoice_applicable'   => $this->request->getPost('einvoice_applicable') ? 1 : 0,
            'lut_number'            => $p('lut_number'),
            'lut_valid_till'        => ymd((string) $p('lut_valid_till')),
            'invoice_footer'        => $p('invoice_footer'),
            'invoice_terms'         => $p('invoice_terms'),
            'legal_disclaimer'      => $p('legal_disclaimer'),
            'fy_start_month'        => max(1, min(12, (int) ($p('fy_start_month') ?? 4))),
            'default_payment_terms_days' => max(0, min(365, (int) ($p('default_payment_terms_days') ?? 15))),
            'rounding_policy'       => $p('rounding_policy') === 'none' ? 'none' : 'nearest_rupee',
            'default_price_mode'    => $p('default_price_mode') === 'inclusive' ? 'inclusive' : 'exclusive',
            'contact_persons'       => json_encode($this->parseContacts((string) $p('contact_persons'))),
            'updated_by'            => $this->userId,
        ];

        $errors = [];
        if (empty($data['legal_name'])) {
            $errors['legal_name'] = 'Legal / registered business name is required (exactly as on the GST certificate).';
        }
        if (empty($data['state_code'])) {
            $errors['state_code'] = 'State is required — it decides CGST+SGST vs IGST.';
        }
        if ($data['gst_registration_type'] !== 'unregistered') {
            $v = Gstin::validate($data['gstin']);
            if (! $v['valid']) {
                $errors['gstin'] = $v['error'];
            } else {
                if ($data['state_code'] && $v['state_code'] !== $data['state_code']) {
                    $errors['state_code'] = "State must match the GSTIN state code ({$v['state_code']}).";
                }
                if ($data['pan'] && $data['pan'] !== $v['pan']) {
                    $errors['pan'] = 'PAN must match characters 3–12 of the GSTIN (' . $v['pan'] . ').';
                }
                $data['pan'] ??= $v['pan'];
            }
        } else {
            $data['gstin'] = null;
        }
        if ($data['pan'] && ! Gstin::validPan($data['pan'])) {
            $errors['pan'] = 'PAN format is invalid (e.g. ABCDE1234F).';
        }
        foreach (['reg_pincode', 'bill_pincode'] as $pin) {
            if ($data[$pin] && ! preg_match('/^[1-9][0-9]{5}$/', (string) $data[$pin])) {
                $errors[$pin] = 'PIN code must be 6 digits.';
            }
        }
        foreach (['email', 'alt_email'] as $em) {
            if ($data[$em] && ! filter_var($data[$em], FILTER_VALIDATE_EMAIL)) {
                $errors[$em] = 'Enter a valid email address.';
            }
        }
        if ($data['lut_number'] && ! $data['lut_valid_till']) {
            $errors['lut_valid_till'] = 'Enter the LUT validity date.';
        }
        if ($errors !== []) {
            return $this->backWithErrors($errors);
        }
        if ($data['bill_address_same']) {
            foreach (['address_line1', 'address_line2', 'city', 'district', 'pincode'] as $f) {
                $data['bill_' . $f] = $data['reg_' . $f];
            }
            $data['bill_state_code'] = $data['state_code'];
        }

        $version            = (int) ($before['profile_version'] ?? 1) + ($before === [] || empty($before['legal_name']) ? 0 : 1);
        $data['profile_version'] = $version;
        $steps              = json_decode((string) ($before['setup_steps'] ?? '{}'), true) ?: [];
        $steps['company']   = true;
        $data['setup_steps'] = json_encode($steps);

        $this->db->transBegin();
        try {
            $this->db->table('company_settings')->where('id', 1)->update($data);
            $this->db->query('INSERT INTO company_profile_versions (profile_version, data, created_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data), created_by = VALUES(created_by), created_at = NOW()', [$version, json_encode($data), $this->userId]);
            service('audit')->log('company.profile_updated', 'company_settings', 1, $before, $data, 'Company legal profile saved (v' . $version . ')');
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        if ($this->request->getPost('wizard')) {
            return redirect()->to(site_url('settings/branding?wizard=1'))->with('success', 'Legal profile saved. Next: brand assets.');
        }

        return redirect()->back()->with('success', 'Company profile saved (version ' . $version . '). Existing finalized documents keep their original snapshot.');
    }

    /** "Name | Role | Phone | Email" per line → list of contacts. */
    private function parseContacts(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (($parts[0] ?? '') !== '') {
                $out[] = ['name' => $parts[0], 'role' => $parts[1] ?? '', 'phone' => $parts[2] ?? '', 'email' => $parts[3] ?? ''];
            }
        }

        return array_slice($out, 0, 10);
    }
}
