<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Models\BuyerProfileModel;
use App\Models\CompanyModel;
use App\Models\SupplierProfileModel;

class Profile extends AreaController
{
    public function edit(): string
    {
        $c = $this->company();
        $profile = $c['company_type'] === 'supplier'
            ? model(SupplierProfileModel::class)->where('company_id', $c['id'])->first()
            : model(BuyerProfileModel::class)->where('company_id', $c['id'])->first();

        return $this->page('account/profile', [
            'title' => 'Company profile', 'profile' => $profile,
            'canPublic' => entitled('public_profile', $c), 'canContact' => entitled('public_contact', $c),
        ]);
    }

    public function update()
    {
        $c     = $this->company();
        $rules = [
            'legal_name' => 'required|max_length[191]', 'trade_name' => 'permit_empty|max_length[191]', 'registration_number' => 'permit_empty|max_length[80]',
            'business_type' => 'permit_empty|max_length[60]', 'year_established' => 'permit_empty|integer|greater_than[1800]|less_than_equal_to[' . date('Y') . ']',
            'employee_count' => 'permit_empty|max_length[30]', 'country_code' => 'required|exact_length[2]|is_not_unique[countries.iso2]',
            'state' => 'permit_empty|max_length[100]', 'city' => 'required|max_length[100]', 'address_line1' => 'required|max_length[191]', 'address_line2' => 'permit_empty|max_length[191]',
            'postal_code' => 'permit_empty|max_length[20]', 'phone' => 'required|max_length[40]', 'email' => 'required|valid_email', 'website' => 'permit_empty|valid_url_strict|max_length[191]',
            'description' => 'permit_empty|max_length[3000]',
        ];
        if ($r = $this->invalid($rules)) {
            return $r;
        }
        $data = $this->request->getPost(array_keys($rules));
        $data['year_established'] = $data['year_established'] ?: null;
        // Verified companies cannot silently change legal identity or country.
        if ($c['verification_status'] === 'verified' && ($data['legal_name'] !== $c['legal_name'] || $data['country_code'] !== $c['country_code'] || ($data['registration_number'] ?? '') !== (string) $c['registration_number'])) {
            return redirect()->back()->withInput()->with('error', 'Legal name, country and registration number of a verified company can only be changed by BearingCave. Please contact support.');
        }
        $data['public_profile_enabled'] = entitled('public_profile', $c) && $this->request->getPost('public_profile_enabled') === '1' ? 1 : 0;
        $data['show_contact_public']    = entitled('public_contact', $c) && $this->request->getPost('show_contact_public') === '1' ? 1 : 0;
        model(CompanyModel::class)->update($c['id'], $data);

        if ($c['company_type'] === 'supplier') {
            $p = $this->request->getPost(['supplier_type', 'main_categories', 'brands_handled', 'production_capacity', 'warehouse_count', 'export_markets', 'logistics_preference', 'default_confidential']);
            $valid = ['manufacturer', 'oem_supplier', 'aftermarket_supplier', 'distributor', 'trader', 'stockist', 'other'];
            model(SupplierProfileModel::class)->where('company_id', $c['id'])->set([
                'supplier_type' => in_array($p['supplier_type'], $valid, true) ? $p['supplier_type'] : 'other',
                'main_categories' => mb_substr((string) $p['main_categories'], 0, 2000), 'brands_handled' => mb_substr((string) $p['brands_handled'], 0, 2000),
                'production_capacity' => mb_substr((string) $p['production_capacity'], 0, 191), 'warehouse_count' => ctype_digit((string) $p['warehouse_count']) ? (int) $p['warehouse_count'] : null,
                'export_markets' => mb_substr((string) $p['export_markets'], 0, 2000),
                'logistics_preference' => in_array($p['logistics_preference'], ['platform_managed', 'supplier_direct', 'both'], true) ? $p['logistics_preference'] : 'platform_managed',
                'default_confidential' => $p['default_confidential'] === '1' ? 1 : 0,
            ])->update();
        } else {
            $p = $this->request->getPost(['buyer_category', 'annual_purchase_volume', 'interested_categories', 'preferred_currency']);
            model(BuyerProfileModel::class)->where('company_id', $c['id'])->set([
                'buyer_category' => mb_substr((string) $p['buyer_category'], 0, 80), 'annual_purchase_volume' => mb_substr((string) $p['annual_purchase_volume'], 0, 60),
                'interested_categories' => mb_substr((string) $p['interested_categories'], 0, 2000), 'preferred_currency' => isset(currency_options()[$p['preferred_currency']]) ? $p['preferred_currency'] : null,
            ])->update();
        }
        service('audit')->log('company.profile_updated', ['entity_type' => 'company', 'entity_id' => (int) $c['id'], 'company_id' => (int) $c['id']]);

        return redirect()->to(site_url($c['company_type'] . '/company'))->with('success', 'Company profile saved.');
    }
}
