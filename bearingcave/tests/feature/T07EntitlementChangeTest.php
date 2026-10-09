<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MembershipPlanModel;
use App\Services\EntitlementService;
use Tests\Support\BCTestCase;

/**
 * TEST 7: Admin updates membership entitlements → effective access changes
 * without modifying code.
 */
final class T07EntitlementChangeTest extends BCTestCase
{
    private function postPlan($admin, array $plan, array $overrides): void
    {
        $ents = [];
        $limits = [];
        foreach (db_connect()->table('plan_entitlements')->where('plan_id', $plan['id'])->get()->getResultArray() as $e) {
            $ents[$e['feature_key']]   = (string) $e['is_enabled'];
            $limits[$e['feature_key']] = $e['limit_value'] === null ? '' : (string) $e['limit_value'];
        }
        $ents = array_merge($ents, $overrides);
        $this->postAs($admin, 'admin/memberships/' . $plan['id'], ['name' => $plan['name'], 'annual_fee' => $plan['annual_fee'], 'currency' => $plan['currency'], 'duration_months' => $plan['duration_months'],
            'description' => $plan['description'], 'is_active' => '1', 'approval_status' => 'pending_approval', 'ent' => $ents, 'limit' => $limits])->assertRedirect();
    }

    public function testDisablingAndEnablingFeaturesTakesEffectImmediately(): void
    {
        $s     = $this->verifiedSupplier();
        $admin = $this->staff('superadmin');
        $plan  = model(MembershipPlanModel::class)->where('code', 'supplier_verified')->first();

        $__r = $this->getAs($s['user'], 'supplier/imports');
        $__r->assertOK();
        $__r->assertSee('Bulk inventory upload');

        $this->postPlan($admin, $plan, ['bulk_import' => '0']);
        $this->getAs($s['user'], 'supplier/imports')->assertRedirectTo(site_url('supplier/membership'));
        $this->assertStringContainsString(EntitlementService::FEATURES['bulk_import'][0], (string) $this->flashError());

        $this->postPlan($admin, $plan, ['bulk_import' => '1']);
        $this->getAs($s['user'], 'supplier/imports')->assertOK();

        // Free plan: grant per-piece pricing to every free supplier by configuration only.
        $free  = $this->registerCompany('supplier');
        $fplan = model(MembershipPlanModel::class)->where('code', 'supplier_free')->first();
        $this->flush();
        $this->assertFalse(service('entitlements')->has($free['company'], 'per_piece_pricing'));
        $this->postPlan($admin, $fplan, ['per_piece_pricing' => '1']);
        $this->flush();
        $this->assertTrue(service('entitlements')->has($free['company'], 'per_piece_pricing'));
        $this->assertSame(1, db_connect()->table('audit_logs')->where('event', 'membership.plan_updated')->where('entity_id', $fplan['id'])->countAllResults());
    }
}
