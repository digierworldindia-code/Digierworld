<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompanyModel;
use App\Models\MembershipPlanModel;
use App\Models\PlanEntitlementModel;

/**
 * Resolves which membership plan applies to a company and which features
 * that plan grants. Admins edit plan entitlements in the admin panel and the
 * effect is immediate everywhere because every feature check goes through here.
 */
class EntitlementService
{
    /**
     * Feature catalogue: key => [label, audience].
     */
    public const FEATURES = [
        // Supplier features
        'verified_badge'             => ['Verified Supplier badge', 'supplier'],
        'public_profile'             => ['Public company profile & optional branding', 'supplier'],
        'public_contact'             => ['Optional public contact information', 'supplier'],
        'priority_ranking'           => ['Priority marketplace ranking (limit = ranking boost points)', 'supplier'],
        'direct_enquiries'           => ['Direct buyer enquiries', 'supplier'],
        'premium_rfq_leads'          => ['Receive premium RFQ leads', 'supplier'],
        'supplier_bidding'           => ['Submit quotations / bids on RFQs', 'supplier'],
        'per_piece_pricing'          => ['Per-piece pricing & smaller-quantity sales', 'supplier'],
        'country_visibility_control' => ['Select allowed / excluded countries', 'supplier'],
        'bulk_import'                => ['Excel/CSV inventory upload', 'supplier'],
        'advanced_inventory'         => ['Advanced inventory controls (tiers, lots, documents)', 'supplier'],
        'advanced_analytics'         => ['Advanced dashboard & performance analytics', 'supplier'],
        'demand_insights'            => ['Demand trend insights', 'supplier'],
        'dedicated_account_manager'  => ['Dedicated BearingCave account manager', 'supplier'],
        'direct_logistics'           => ['Supplier-direct logistics option', 'supplier'],
        'max_active_products'        => ['Maximum published products (limit; empty = unlimited)', 'supplier'],
        // Buyer features
        'buyer_badge'                => ['Verified Buyer badge', 'buyer'],
        'rfq_submission'             => ['Submit RFQs', 'buyer'],
        'max_open_rfqs'              => ['Maximum open RFQs (limit; empty = unlimited)', 'buyer'],
        'restricted_inventory_access' => ['Eligible for approved restricted inventory', 'buyer'],
        'advanced_comparison'        => ['Advanced supplier comparison', 'buyer'],
        'consolidation_services'     => ['Multi-supplier consolidation services', 'buyer'],
        'buyer_analytics'            => ['Search & engagement analytics', 'buyer'],
    ];

    /** @var array<int,?array> */
    private array $planCache = [];

    /** @var array<int,array<string,array>> */
    private array $entCache = [];

    public function plan(array|int $company): ?array
    {
        $company = is_array($company) ? $company : model(CompanyModel::class)->find($company);
        if ($company === null) {
            return null;
        }
        $id = (int) $company['id'];
        if (array_key_exists($id, $this->planCache)) {
            return $this->planCache[$id];
        }

        $plans = model(MembershipPlanModel::class);
        $plan  = null;

        if ($company['company_type'] === 'supplier') {
            $sub = db_connect()->table('subscriptions s')
                ->select('mp.*')
                ->join('membership_plans mp', 'mp.id = s.plan_id')
                ->where('s.company_id', $id)
                ->where('s.status', 'active')
                ->where('s.starts_on <=', date('Y-m-d'))
                ->where('s.ends_on >=', date('Y-m-d'))
                ->where('mp.is_active', 1)
                ->orderBy('mp.annual_fee', 'DESC')
                ->get()->getRowArray();
            if ($sub && (! $sub['requires_verification'] || $company['verification_status'] === 'verified')) {
                $plan = $sub;
            }
        } elseif ($company['verification_status'] === 'verified') {
            $plan = $plans->where('code', service('settings_store')->get('buyer.verified_plan_code', 'buyer_verified'))->first();
        }

        if ($company['status'] !== 'active') {
            $plan = null; // suspended accounts fall back to the most restrictive default plan
        }

        $plan ??= $plans->where('audience', $company['company_type'])->where('is_default', 1)->where('is_active', 1)->first();

        return $this->planCache[$id] = $plan;
    }

    private function entitlements(?array $plan): array
    {
        if ($plan === null) {
            return [];
        }
        $pid = (int) $plan['id'];
        if (! isset($this->entCache[$pid])) {
            $rows = model(PlanEntitlementModel::class)->where('plan_id', $pid)->findAll();
            $this->entCache[$pid] = array_column($rows, null, 'feature_key');
        }

        return $this->entCache[$pid];
    }

    public function has(array|int|null $company, string $feature): bool
    {
        if ($company === null) {
            return false;
        }
        $e = $this->entitlements($this->plan($company))[$feature] ?? null;

        return $e !== null && (bool) $e['is_enabled'];
    }

    /**
     * Numeric limit for a feature; null means unlimited (or feature disabled — check has()).
     */
    public function limit(array|int $company, string $feature): ?int
    {
        $e = $this->entitlements($this->plan($company))[$feature] ?? null;

        return $e && $e['limit_value'] !== null ? (int) $e['limit_value'] : null;
    }

    public function isVerifiedSupplier(?array $company): bool
    {
        return $company !== null
            && $company['company_type'] === 'supplier'
            && $company['verification_status'] === 'verified'
            && $company['status'] === 'active'
            && $this->has($company, 'verified_badge');
    }

    public function isVerifiedBuyer(?array $company): bool
    {
        return $company !== null
            && $company['company_type'] === 'buyer'
            && $company['verification_status'] === 'verified'
            && $company['status'] === 'active';
    }

    public function flush(): void
    {
        $this->planCache = [];
        $this->entCache  = [];
    }
}
