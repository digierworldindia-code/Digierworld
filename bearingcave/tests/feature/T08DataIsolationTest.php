<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OrderModel;
use Tests\Support\BCTestCase;

/**
 * TEST 8: User tries accessing unauthorized company data → access denied
 * and a security event is logged.
 */
final class T08DataIsolationTest extends BCTestCase
{
    public function testCrossCompanyAccessIsDeniedAndLogged(): void
    {
        $s1 = $this->verifiedSupplier();
        $s2 = $this->registerCompany('supplier');
        $p1 = $this->product($s1['company']);
        $b1 = $this->registerCompany('buyer');
        $b2 = $this->registerCompany('buyer');

        service('orders');
        $this->postAs($b1['user'], "buyer/cart/add/{$p1['id']}", ['quantity' => '5'])->assertRedirect();
        $this->postAs($b1['user'], 'buyer/cart/checkout', ['delivery_country' => 'IN', 'delivery_address' => 'X', 'logistics_mode' => 'platform_managed', 'accept_terms' => '1'])->assertRedirect();
        $order = model(OrderModel::class)->where('buyer_company_id', $b1['company']['id'])->first();

        $before = $this->securityEvents('access.');

        $this->assertNotFoundFor($b2['user'], 'buyer/orders/' . $order['id']);
        $this->assertNotFoundFor($s2['user'], 'supplier/orders/' . $order['id']);
        $this->assertNotFoundFor($s2['user'], "supplier/products/{$p1['id']}/edit");
        $this->assertNotFoundFor($s2['user'], "supplier/products/{$p1['id']}", 'post', ['name' => 'hijack']);
        $this->assertNotFoundFor($b2['user'], 'buyer/billing/invoices/1');

        $this->assertGreaterThanOrEqual($before + 5, $this->securityEvents('access.'));
        $this->assertSame('Test bearing', db_connect()->table('products')->where('id', $p1['id'])->get()->getRow()->name, 'no write happened');

        // Area separation and admin gate.
        $this->getAs($b2['user'], 'supplier/dashboard')->assertRedirectTo(site_url('dashboard'));
        $this->assertNotFoundFor($b2['user'], 'admin');
        $this->assertGreaterThan(0, $this->securityEvents('access.admin_denied'));

        // Staff without the permission are blocked by Shield permission filters.
        $support = $this->staff('support_executive');
        $this->getAs($support, 'admin/settings')->assertRedirect();
        $this->getAs($support, 'admin/disputes')->assertOK();

        // Private documents are never served to other companies.
        $this->actingAs($b1['user']);
        $doc = service('documents')->store($this->pdf('kyc.pdf'), ['company_id' => (int) $b1['company']['id'], 'entity_type' => 'company', 'entity_id' => (int) $b1['company']['id'], 'doc_type' => 'pan_card', 'title' => 'PAN']);
        $this->assertNotFoundFor($b2['user'], 'documents/' . $doc['uuid']);
        $this->getAs(null, 'documents/' . $doc['uuid'])->assertRedirect();
        $this->getAs($b1['user'], 'documents/' . $doc['uuid'])->assertOK();
        $this->assertGreaterThan(0, $this->securityEvents('document.denied'));
    }
}
