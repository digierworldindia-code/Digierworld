<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\Viewer;
use App\Models\BuyerProfileModel;
use CodeIgniter\Database\BaseBuilder;

/**
 * The ONE place where catalog visibility rules live.
 *
 * Every product query that can reach a non-owner (search, detail pages,
 * APIs, RFQ matching, recommendations, comparison, cart, exports, sitemap)
 * must go through apply()/canView(). Rules:
 *  - only published products of active suppliers;
 *  - "verified_buyers" listings need a verified buyer that BearingCave has
 *    explicitly approved for restricted inventory (not merely registered);
 *  - product allow/deny country lists and supplier-wide country rules are
 *    enforced against the buyer's registered company country;
 *  - guests (no known country) only see unrestricted, public listings.
 * Restrictions fail closed: if a supplier's plan later loses the
 * country-control feature, existing restrictions still apply.
 */
class VisibilityService
{
    private ?Viewer $current = null;

    public function current(): Viewer
    {
        if ($this->current !== null) {
            return $this->current;
        }
        if (! auth()->loggedIn()) {
            return $this->current = Viewer::guest();
        }

        $ctx     = service('companyContext');
        $userId  = (int) auth()->id();
        $company = $ctx->company($userId);
        $isStaff = $ctx->isStaff($userId);
        $ent     = service('entitlements');

        $verifiedBuyer = $ent->isVerifiedBuyer($company);
        $restricted    = false;
        if ($verifiedBuyer && $ent->has($company, 'restricted_inventory_access')) {
            $profile    = model(BuyerProfileModel::class)->where('company_id', $company['id'])->first();
            $restricted = (bool) ($profile['restricted_inventory_access'] ?? false);
        }

        return $this->current = new Viewer(
            $userId,
            $company,
            $company['country_code'] ?? null,
            $isStaff,
            $verifiedBuyer,
            $restricted,
        );
    }

    public function reset(): void
    {
        $this->current = null;
    }

    /**
     * Restricts a products query (aliased) to what the viewer may see.
     */
    public function apply(BaseBuilder $b, Viewer $v, string $p = 'p'): BaseBuilder
    {
        $b->where("{$p}.deleted_at", null);

        if ($v->isStaff) {
            return $b;
        }

        $db = db_connect();
        $b->where("{$p}.status", 'published');
        $b->where("EXISTS (SELECT 1 FROM companies vc WHERE vc.id = {$p}.company_id AND vc.status = 'active' AND vc.deleted_at IS NULL)", null, false);

        if ($v->canSeeRestricted) {
            $b->whereIn("{$p}.visibility", ['public', 'verified_buyers']);
        } else {
            $b->where("{$p}.visibility", 'public');
        }

        if ($v->country === null || ! preg_match('/^[A-Z]{2}$/', $v->country)) {
            $b->where("{$p}.country_mode", 'all');
            $b->where("NOT EXISTS (SELECT 1 FROM company_country_rules vr WHERE vr.company_id = {$p}.company_id)", null, false);

            return $b;
        }

        $cc = $db->escape($v->country);
        $b->where("(
            {$p}.country_mode = 'all'
            OR ({$p}.country_mode = 'allow_list' AND EXISTS (SELECT 1 FROM product_country_rules pr WHERE pr.product_id = {$p}.id AND pr.rule = 'allow' AND pr.country_code = {$cc}))
            OR ({$p}.country_mode = 'deny_list' AND NOT EXISTS (SELECT 1 FROM product_country_rules pr WHERE pr.product_id = {$p}.id AND pr.rule = 'deny' AND pr.country_code = {$cc}))
        )", null, false);
        $b->where("NOT EXISTS (SELECT 1 FROM company_country_rules vr WHERE vr.company_id = {$p}.company_id AND vr.rule = 'deny' AND vr.country_code = {$cc})", null, false);
        $b->where("(NOT EXISTS (SELECT 1 FROM company_country_rules vr WHERE vr.company_id = {$p}.company_id AND vr.rule = 'allow')
            OR EXISTS (SELECT 1 FROM company_country_rules vr WHERE vr.company_id = {$p}.company_id AND vr.rule = 'allow' AND vr.country_code = {$cc}))", null, false);

        return $b;
    }

    public function canView(array $product, ?Viewer $v = null): bool
    {
        $v ??= $this->current();
        if ($v->isStaff || $v->owns($product)) {
            return $product['deleted_at'] === null || $v->isStaff;
        }
        $b = db_connect()->table('products p')->select('p.id')->where('p.id', $product['id']);

        return $this->apply($b, $v)->countAllResults() > 0;
    }

    /**
     * Whether a listing may appear in sitemaps / be indexed by search engines.
     */
    public function isIndexable(array $product): bool
    {
        return $this->canView($product, Viewer::guest());
    }

    public function canSeePrice(array $product, ?Viewer $v = null): bool
    {
        $v ??= $this->current();
        if ($v->isStaff || $v->owns($product)) {
            return true;
        }
        if ($product['price_visibility'] !== 'show') {
            return false;
        }

        return ! $v->isGuest() || (bool) service('settings_store')->get('catalog.guest_can_see_prices', false);
    }

    /**
     * Whether the supplier's real identity can be shown to this viewer.
     */
    public function canSeeSupplierIdentity(array $supplier, ?array $product = null, ?Viewer $v = null): bool
    {
        $v ??= $this->current();
        if ($v->isStaff || $v->companyId() === (int) $supplier['id']) {
            return true;
        }
        if ($product !== null && (int) $product['hide_supplier_identity'] === 1) {
            return false;
        }

        return (bool) $supplier['public_profile_enabled']
            && service('entitlements')->isVerifiedSupplier($supplier)
            && service('entitlements')->has($supplier, 'public_profile');
    }

    public function canSeeSupplierContact(array $supplier, ?array $product = null, ?Viewer $v = null): bool
    {
        $v ??= $this->current();
        if ($v->isStaff || $v->companyId() === (int) $supplier['id']) {
            return true;
        }

        return $this->canSeeSupplierIdentity($supplier, $product, $v)
            && (bool) $supplier['show_contact_public']
            && service('entitlements')->has($supplier, 'public_contact')
            && ! $v->isGuest();
    }

    public function supplierLabel(array $supplier, ?array $product = null, ?Viewer $v = null): string
    {
        if ($this->canSeeSupplierIdentity($supplier, $product, $v)) {
            return $supplier['trade_name'] ?: $supplier['legal_name'];
        }

        return 'Supplier ' . $supplier['public_alias'];
    }
}
