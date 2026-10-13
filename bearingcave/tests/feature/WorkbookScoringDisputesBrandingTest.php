<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OrderModel;
use Tests\Support\BCTestCase;

/**
 * Workbook "Platform Features" 1.4 / client scoring / 3.3 demand insights /
 * 4.2 dispute intervention, and supplier feature "Branding & Marking".
 */
final class WorkbookScoringDisputesBrandingTest extends BCTestCase
{
    public function testSupplierComplianceAndClientServicesBonusScores(): void
    {
        $s  = $this->verifiedSupplier();
        $db = db_connect();
        foreach ([['verified', '+1 year'], ['verified', '-1 day'], ['rejected', '+1 year']] as [$st, $exp]) {
            $db->table('compliance_certifications')->insert(['company_id' => $s['company']['id'], 'cert_type' => 'ISO9001', 'cert_name' => 'ISO 9001', 'status' => $st, 'expires_on' => date('Y-m-d', strtotime($exp))]);
        }
        $row = service('performance')->computeSupplier((int) $s['company']['id']);
        $this->assertSame(50.0, (float) $row['compliance_score'], 'one of two non-rejected certificates is valid');
        $this->assertSame(50.0, (float) $row['overall_score'], 'with no transactions yet, compliance is the only stored evidence');

        $b = $this->registerCompany('buyer');
        service('logistics')->create($b['company'], (int) $b['user']->id, ['mode' => 'platform_managed', 'destination_country' => 'IN']);
        service('inspections')->create($b['company'], (int) $b['user']->id, ['inspection_type' => 'third_party', 'scope' => ['physical'], 'location_country' => 'IN']);
        $score = service('performance')->computeBuyer((int) $b['company']['id']);
        $this->assertSame(2, $score['services']);
        $this->assertSame(50, (int) $score['bonus'], '25 points per BearingCave service used');
        $this->assertSame(5.0, (float) $score['overall'], '10% weight × 50 bonus; no engagement/transactions yet');
        $profile = $db->table('buyer_profiles')->where('company_id', $b['company']['id'])->get()->getRowArray();
        $this->assertSame(50.0, (float) $profile['services_bonus_score']);
    }

    public function testDisputeInterventionEligibilityFollowsPaidInspection(): void
    {
        $s = $this->verifiedSupplier();
        $p = $this->product($s['company'], ['sale_mode' => 'piece', 'unit_price' => '10']);
        $b = $this->registerCompany('buyer');
        $this->postAs($b['user'], "buyer/cart/add/{$p['id']}", ['quantity' => '20'])->assertRedirect();
        $this->postAs($b['user'], 'buyer/cart/checkout', ['delivery_country' => 'IN', 'delivery_address' => 'Pune', 'logistics_mode' => 'platform_managed', 'accept_terms' => '1'])->assertRedirect();
        $order = model(OrderModel::class)->where('buyer_company_id', $b['company']['id'])->first();
        $this->postAs($s['user'], "supplier/orders/{$order['id']}/status", ['to' => 'confirmed'])->assertRedirect();

        $d1 = service('disputes')->create($b['company'], (int) $b['user']->id, (int) $order['id'], ['category' => 'quality', 'subject' => 'Rust on bearings', 'description' => 'Several bearings show surface rust on arrival.']);
        $this->assertSame(0, (int) $d1['bc_intervention_eligible']);
        $this->getAs($b['user'], 'buyer/disputes/' . $d1['id'])->assertSee('Documentation only');

        $req = service('inspections')->create($b['company'], (int) $b['user']->id, ['inspection_type' => 'in_house', 'scope' => ['physical'], 'order_id' => $order['id'], 'location_country' => 'IN']);
        db_connect()->table('inspection_requests')->where('id', $req['id'])->update(['status' => 'accepted']);
        $d2 = service('disputes')->create($b['company'], (int) $b['user']->id, (int) $order['id'], ['category' => 'quantity_shortfall', 'subject' => 'Short shipment', 'description' => 'Received 18 pieces instead of 20 pieces.']);
        $this->assertSame(1, (int) $d2['bc_intervention_eligible']);
        $this->assertSame((int) $req['id'], (int) $d2['inspection_request_id']);
        $this->getAs($this->staff('support_executive'), 'admin/disputes/' . $d2['id'])->assertSee('Intervention eligible');
    }

    public function testRfqTrendInsightsAreAggregatedAndBrandingIsVerifiedOnly(): void
    {
        $s = $this->verifiedSupplier();
        $this->product($s['company'], ['part_number' => '6204-2RS']);
        foreach (['b1', 'b2'] as $i => $_) {
            $b = $this->registerCompany('buyer');
            service('rfqs')->create($b['company'], (int) $b['user']->id, ['title' => 'Need 6204', 'destination_country' => 'IN', 'currency' => 'INR', 'deadline_at' => date('Y-m-d H:i:s', strtotime('+5 days'))],
                [['part_number' => '6204-2RS', 'description' => 'Ball bearing', 'quantity' => 100 * ($i + 1)]], 'form');
        }
        $single = $this->registerCompany('buyer');
        service('rfqs')->create($single['company'], (int) $single['user']->id, ['title' => 'Need 6204 variant', 'destination_country' => 'IN', 'currency' => 'INR', 'deadline_at' => date('Y-m-d H:i:s', strtotime('+5 days'))],
            [['part_number' => '6204-ZZ-SECRET', 'description' => 'Ball bearing', 'category_id' => db_connect()->table('categories')->where('slug', 'ball-bearings')->get()->getRow()->id, 'quantity' => 7]], 'form');

        $page = $this->getAs($s['user'], 'supplier/analytics');
        $page->assertSee('RFQ demand trend in your categories');
        $page->assertSee('6204-2RS');
        $page->assertSee('300 units');
        $page->assertDontSee('6204-ZZ-SECRET');

        $free = $this->registerCompany('supplier');
        $this->postAs($free['user'], 'supplier/company/logo', ['remove' => '1'])->assertRedirect();
        $this->assertStringContainsString('Verified Supplier plan', (string) $this->flashError());
        $this->getAs($s['user'], 'supplier/company')->assertSee('Upload logo');
    }
}
