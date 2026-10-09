<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LogisticsRequestModel;
use App\Models\OrderModel;
use Tests\Support\BCTestCase;

/**
 * TEST 6: Supplier chooses confidential shipping → buyer cannot see the
 * restricted supplier identity → logistics request managed correctly.
 */
final class T06ConfidentialShippingTest extends BCTestCase
{
    public function testConfidentialShipmentHidesSupplier(): void
    {
        $s = $this->verifiedSupplier();
        $p = $this->product($s['company'], ['hide_supplier_identity' => 1]);
        $b = $this->registerCompany('buyer', 'AE');

        $this->postAs($b['user'], "buyer/cart/add/{$p['id']}", ['quantity' => '20'])->assertRedirect();
        $this->postAs($b['user'], 'buyer/cart/checkout', ['delivery_country' => 'AE', 'delivery_address' => 'Warehouse 7, Dubai', 'logistics_mode' => 'confidential', 'accept_terms' => '1'])->assertRedirect();
        $order = model(OrderModel::class)->where('buyer_company_id', $b['company']['id'])->first();
        $this->assertSame('confidential', $order['logistics_mode']);

        // Supplier requests confidential pickup with its private address.
        $this->postAs($s['user'], "supplier/orders/{$order['id']}/status", ['to' => 'confirmed'])->assertRedirect();
        $this->postAs($s['user'], 'supplier/logistics', ['mode' => 'confidential', 'order_id' => $order['id'], 'pickup_country' => 'IN', 'pickup_city' => 'Pune',
            'pickup_address' => 'SECRET-PLANT-ADDRESS 42', 'pickup_contact' => 'Store manager', 'destination_country' => 'AE'])->assertRedirect();
        $lr = model(LogisticsRequestModel::class)->where('order_id', $order['id'])->first();
        $this->assertSame('confidential', $lr['mode']);

        // Buyer views: no supplier name, no pickup address.
        $orderPage = $this->getAs($b['user'], 'buyer/orders/' . $order['id']);
        $__r = $orderPage;
        $__r->assertOK();
        $__r->assertDontSee($s['company']['legal_name']);
        $__r->assertSee('confidential');
        $lPage = $this->getAs($b['user'], 'buyer/logistics/' . $lr['id']);
        $__r = $lPage;
        $__r->assertOK();
        $__r->assertDontSee('SECRET-PLANT-ADDRESS');
        $__r->assertDontSee('Store manager');
        $__r->assertSee('Confidential');
        $__r = $this->getAs($b['user'], 'product/' . $p['slug']);
        $__r->assertOK();
        $__r->assertDontSee($s['company']['legal_name']);

        // Supplier and BearingCave logistics see the real pickup details.
        $__r = $this->getAs($s['user'], 'supplier/logistics/' . $lr['id']);
        $__r->assertOK();
        $__r->assertSee('SECRET-PLANT-ADDRESS');
        $lm = $this->staff('logistics_manager');
        $__r = $this->getAs($lm, 'admin/logistics/' . $lr['id']);
        $__r->assertOK();
        $__r->assertSee('SECRET-PLANT-ADDRESS');

        // Logistics manages it: quote → accept → book → deliver; order follows.
        $this->postAs($lm, "admin/logistics/{$lr['id']}/quote", ['quoted_fee' => '1800'])->assertRedirect();
        $this->postAs($s['user'], "supplier/logistics/{$lr['id']}/respond", ['decision' => 'accept'])->assertRedirect();
        $this->postAs($lm, "admin/logistics/{$lr['id']}/book", ['carrier' => 'Test Freight', 'tracking_number' => 'TF123', 'transport_mode' => 'sea', 'eta' => date('Y-m-d', strtotime('+20 days'))])->assertRedirect();
        $this->assertSame('booked', model(LogisticsRequestModel::class)->find($lr['id'])['status']);
        // The buyer pays the proforma (finance confirms), supplier starts fulfilment.
        $inv = db_connect()->table('invoices')->where('reference_type', 'order')->where('reference_id', $order['id'])->get()->getRowArray();
        $this->postAs($this->staff('finance_manager'), "admin/invoices/{$inv['id']}/record", ['amount' => $inv['total'], 'reference' => 'UTR-T6', 'paid_on' => date('Y-m-d')])->assertRedirect();
        $this->postAs($s['user'], "supplier/orders/{$order['id']}/status", ['to' => 'in_fulfilment'])->assertRedirect();
        $shipment = db_connect()->table('shipments')->where('logistics_request_id', $lr['id'])->get()->getRowArray();
        $this->postAs($lm, "admin/logistics/shipments/{$shipment['id']}", ['status' => 'delivered', 'location' => 'Dubai'])->assertRedirect();
        $order = model(OrderModel::class)->find($order['id']);
        $this->assertSame('delivered', $order['status']);
        $this->assertSame(80, (int) db_connect()->table('products')->where('id', $p['id'])->get()->getRow()->stock_on_hand, 'stock dispatched on shipment');
    }
}
