<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\OrderModel;
use Tests\Support\BCTestCase;

/**
 * Workbook: inspection can be requested at an additional cost before purchase
 * confirmation; consolidation is a verified-buyer privilege and available to
 * non-verified buyers at additional charges; warranty terms per supplier tier.
 */
final class CheckoutServiceOptionsTest extends BCTestCase
{
    public function testInspectionRequestedAtCheckoutIsTenPercentOfEachOrder(): void
    {
        $s = $this->verifiedSupplier();
        $p = $this->product($s['company'], ['sale_mode' => 'piece', 'unit_price' => '20']);
        $b = $this->registerCompany('buyer');

        $this->postAs($b['user'], "buyer/cart/add/{$p['id']}", ['quantity' => '50'])->assertRedirect();
        $this->getAs($b['user'], 'buyer/cart/checkout')->assertSee('Optional inspection before purchase confirmation');
        $this->postAs($b['user'], 'buyer/cart/checkout', ['delivery_country' => 'IN', 'delivery_address' => 'Pune', 'logistics_mode' => 'platform_managed', 'accept_terms' => '1',
            'request_inspection' => '1', 'inspection_type' => 'in_house', 'inspection_scope' => ['physical', 'quantity']])->assertRedirect();

        $order = model(OrderModel::class)->where('buyer_company_id', $b['company']['id'])->first();
        $this->assertSame('pending_supplier_confirmation', $order['status'], 'inspection is requested before the purchase is confirmed');
        $req = db_connect()->table('inspection_requests')->where('order_id', $order['id'])->get()->getRowArray();
        $this->assertNotNull($req);
        $this->assertSame(1, (int) $req['requested_at_checkout']);
        $this->assertSame(100.0, (float) $req['estimated_fee'], '10% of the 1,000 invoice value');
        $this->assertSame(['physical', 'quantity'], json_decode($req['scope'], true));
        $this->assertSame('IN', $req['location_country']);
    }

    public function testConsolidationAndWarrantyNotesFollowTheWorkbook(): void
    {
        $buyer = $this->registerCompany('buyer');
        $req   = service('logistics')->create($buyer['company'], (int) $buyer['user']->id, ['mode' => 'consolidation', 'destination_country' => 'IN', 'consolidation_ref' => 'GRP-1']);
        $this->assertSame('consolidation', $req['mode']);
        $this->assertStringContainsString('additional charges', (string) $req['admin_notes']);

        $vb  = $this->verifyCompany($this->registerCompany('buyer')['company']);
        $this->flush();
        $req = service('logistics')->create($vb, (int) db_connect()->table('company_members')->where('company_id', $vb['id'])->get()->getRow()->user_id, ['mode' => 'consolidation', 'destination_country' => 'IN']);
        $this->assertStringContainsString('verified buyer privilege', (string) $req['admin_notes']);

        $supplier = $this->registerCompany('supplier');
        try {
            service('logistics')->create($supplier['company'], (int) $supplier['user']->id, ['mode' => 'consolidation', 'destination_country' => 'IN']);
            $this->fail('suppliers cannot request buyer consolidation');
        } catch (BusinessRuleException) {
        }

        $p = $this->product($supplier['company']);
        $this->getAs($buyer['user'], 'product/' . $p['slug'])->assertSee('No warranty. BearingCave intervention applies when inspection services are opted for');
        $vp = $this->product($this->verifiedSupplier()['company']);
        $this->getAs($buyer['user'], 'product/' . $vp['slug'])->assertSee('sole responsibility of the supplier and the client');
    }
}
