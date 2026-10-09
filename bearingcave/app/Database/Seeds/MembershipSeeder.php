<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Membership plans and entitlements from the requirements:
 *  Plan A — Unverified Supplier (free)   Plan B — Verified Supplier (₹50,000 / year)
 * Buyers: Standard (unverified) and Verified (both free).
 * All values are editable in Admin → Membership Plans.
 */
class MembershipSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            'supplier_free' => ['Unverified Supplier', 'supplier', 0, 0, 1, 'Free registration: private profile, lot-based listings, BearingCave-managed shipping, basic enquiry management.', 1,
                ['direct_enquiries' => [1, null], 'max_active_products' => [1, null], 'priority_ranking' => [0, null]]],
            'supplier_verified' => ['Verified Supplier', 'supplier', 50000, 1, 0, 'Verified badge, public profile & branding, priority ranking, premium RFQ leads, bidding, per-piece pricing, country visibility, bulk upload, analytics and a dedicated account manager.', 2,
                ['verified_badge' => [1, null], 'public_profile' => [1, null], 'public_contact' => [1, null], 'priority_ranking' => [1, 50], 'direct_enquiries' => [1, null],
                    'premium_rfq_leads' => [1, null], 'supplier_bidding' => [1, null], 'per_piece_pricing' => [1, null], 'country_visibility_control' => [1, null], 'bulk_import' => [1, null],
                    'advanced_inventory' => [1, null], 'advanced_analytics' => [1, null], 'demand_insights' => [1, null], 'dedicated_account_manager' => [1, null], 'direct_logistics' => [1, null], 'max_active_products' => [1, null]]],
            'buyer_standard' => ['Buyer', 'buyer', 0, 0, 1, 'Free registration: browse, search, standard RFQs, quotations, orders, inspection and logistics requests, disputes.', 3,
                ['rfq_submission' => [1, null], 'max_open_rfqs' => [1, 5]]],
            'buyer_verified' => ['Verified Buyer', 'buyer', 0, 1, 0, 'Verified badge, eligibility for approved restricted inventory, advanced comparison, consolidation services and analytics.', 4,
                ['buyer_badge' => [1, null], 'rfq_submission' => [1, null], 'max_open_rfqs' => [1, null], 'restricted_inventory_access' => [1, null], 'advanced_comparison' => [1, null],
                    'consolidation_services' => [1, null], 'buyer_analytics' => [1, null]]],
        ];
        foreach ($plans as $code => [$name, $aud, $fee, $reqVer, $default, $desc, $sort, $ents]) {
            $plan = $this->db->table('membership_plans')->where('code', $code)->get()->getRowArray();
            if ($plan) {
                continue;
            }
            $now = date('Y-m-d H:i:s');
            $this->db->table('membership_plans')->insert([
                'code' => $code, 'name' => $name, 'audience' => $aud, 'annual_fee' => $fee, 'currency' => 'INR', 'duration_months' => 12,
                'requires_verification' => $reqVer, 'is_default' => $default, 'is_active' => 1, 'description' => $desc, 'sort_order' => $sort,
                'approval_status' => $code === 'supplier_verified' ? 'pending_approval' : 'approved', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $pid = $this->db->insertID();
            foreach (\App\Services\EntitlementService::FEATURES as $key => [$label, $audience]) {
                if ($audience !== $aud) {
                    continue;
                }
                [$on, $limit] = $ents[$key] ?? [0, null];
                $this->db->table('plan_entitlements')->insert(['plan_id' => $pid, 'feature_key' => $key, 'is_enabled' => $on, 'limit_value' => $limit, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }
}
