<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\CompanyModel;

/**
 * Public directory: only verified suppliers that opted into a public
 * profile AND whose plan includes it. Everyone else stays anonymous.
 */
class Suppliers extends BaseController
{
    public function index(): string
    {
        $ent  = service('entitlements');
        $rows = model(CompanyModel::class)->where('company_type', 'supplier')->where('status', 'active')
            ->where('verification_status', 'verified')->where('public_profile_enabled', 1)->orderBy('legal_name')->findAll(200);
        $rows = array_values(array_filter($rows, static fn ($c) => $ent->isVerifiedSupplier($c) && $ent->has($c, 'public_profile')));

        return view('web/suppliers', [
            'title' => 'Verified automotive suppliers', 'canonical' => site_url('suppliers'),
            'metaDescription' => 'Browse BearingCave verified automotive manufacturers and suppliers that have chosen to publish their company profile.',
            'suppliers' => $rows,
        ]);
    }

    public function show(string $slug): string
    {
        $c = model(CompanyModel::class)->where('slug', $slug)->where('company_type', 'supplier')->first();
        $ent = service('entitlements');
        if (! $c || $c['status'] !== 'active' || ! $c['public_profile_enabled'] || ! $ent->isVerifiedSupplier($c) || ! $ent->has($c, 'public_profile')) {
            $this->notFound();
        }
        $viewer = service('visibility')->current();
        $result = service('search')->search(['supplier_id' => (string) $c['id']], $viewer, max(1, (int) $this->request->getGet('page')), 12, false);
        // Exclude listings the supplier marked as identity-confidential.
        $result['items'] = array_values(array_filter($result['items'], static fn ($p) => ! (int) $p['hide_supplier_identity']));
        $profile = db_connect()->table('supplier_profiles')->where('company_id', $c['id'])->get()->getRowArray();
        $certs   = db_connect()->table('compliance_certifications')->where('company_id', $c['id'])->where('status', 'verified')->get()->getResultArray();

        return view('web/supplier', [
            'title' => ($c['trade_name'] ?: $c['legal_name']), 'canonical' => site_url('suppliers/' . $c['slug']),
            'metaDescription' => 'Verified supplier profile of ' . ($c['trade_name'] ?: $c['legal_name']) . ' on BearingCave.',
            'company' => $c, 'profile' => $profile, 'certs' => $certs, 'result' => $result,
            'showContact' => service('visibility')->canSeeSupplierContact($c, null, $viewer),
            'jsonLd' => ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => $c['trade_name'] ?: $c['legal_name'], 'address' => ['@type' => 'PostalAddress', 'addressLocality' => $c['city'], 'addressCountry' => $c['country_code']]],
        ]);
    }
}
