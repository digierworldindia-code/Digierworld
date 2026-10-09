<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OrderModel;
use App\Models\ProductModel;
use Tests\Support\BCTestCase;

/**
 * TEST 2: Unverified supplier adds bulk inventory → product approved →
 * eligible buyer enquires → transaction workflow initiated.
 */
final class T02UnverifiedSupplierTest extends BCTestCase
{
    public function testFreeSupplierLotListingToOrder(): void
    {
        $s = $this->registerCompany('supplier', 'DE');
        $cat = (int) db_connect()->table('categories')->where('slug', 'ball-bearings')->get()->getRow()->id;

        // Free plan cannot sell per piece.
        $this->postAs($s['user'], 'supplier/products', ['name' => 'Bearing lot', 'sku' => 'LOT-1', 'part_number' => '6204-2RS', 'category_id' => $cat, 'item_condition' => 'new',
            'sale_mode' => 'piece', 'unit_price' => '5', 'moq' => '1', 'currency' => 'EUR', 'price_visibility' => 'show', 'visibility' => 'public', 'country_mode' => 'all',
            'initial_quantity' => '1000', 'received_on' => date('Y-m-d', strtotime('-13 months'))])->assertRedirect();
        $this->assertStringContainsString('Per-piece', (string) $this->flashError());
        $this->assertSame(0, model(ProductModel::class)->where('company_id', $s['company']['id'])->countAllResults());

        // Bulk lot listing is allowed.
        $this->postAs($s['user'], 'supplier/products', ['name' => 'Bearing lot', 'sku' => 'LOT-1', 'part_number' => '6204-2RS', 'category_id' => $cat, 'item_condition' => 'new_old_stock',
            'sale_mode' => 'lot', 'lot_price' => '4000', 'lot_quantity' => '500', 'moq' => '500', 'currency' => 'EUR', 'price_visibility' => 'show', 'visibility' => 'public', 'country_mode' => 'all',
            'initial_quantity' => '1000', 'received_on' => date('Y-m-d', strtotime('-13 months'))])->assertRedirect();
        $p = model(ProductModel::class)->where('company_id', $s['company']['id'])->first();
        $this->assertSame('draft', $p['status']);
        $this->assertSame(1000, (int) $p['stock_on_hand']);
        $this->assertSame(1, (int) $p['hide_supplier_identity'], 'unverified suppliers are always anonymous');

        $this->postAs($s['user'], "supplier/products/{$p['id']}/submit")->assertRedirect();
        $this->assertSame('pending_review', model(ProductModel::class)->find($p['id'])['status']);
        $this->assertNotFoundFor(null, 'product/' . $p['slug']);

        $pm = $this->staff('procurement_manager');
        $this->postAs($pm, "admin/products/{$p['id']}/review", ['decision' => 'approve'])->assertRedirect();
        $this->assertSame('published', model(ProductModel::class)->find($p['id'])['status']);
        $page = $this->getAs(null, 'product/' . $p['slug']);
        $__r = $page;
        $__r->assertOK();
        $__r->assertDontSee($s['company']['legal_name']);

        // Buyer enquires, then orders a lot.
        $b = $this->registerCompany('buyer', 'IN');
        $this->postAs($b['user'], "product/{$p['id']}/enquire", ['quantity' => '500', 'message' => 'Please confirm batch date codes.'])->assertRedirect();
        $this->assertSame(1, db_connect()->table('product_enquiries')->where('product_id', $p['id'])->countAllResults());

        $this->postAs($b['user'], "buyer/cart/add/{$p['id']}", ['quantity' => '750'])->assertRedirect();
        $this->postAs($b['user'], 'buyer/cart/checkout', ['delivery_country' => 'IN', 'delivery_address' => 'Delhi', 'logistics_mode' => 'platform_managed', 'accept_terms' => '1'])->assertRedirect();
        $this->assertStringContainsString('lots of 500', (string) $this->flashError(), 'lot multiples are enforced');

        $this->postAs($b['user'], 'buyer/cart/items/' . db_connect()->table('cart_items')->get()->getRow()->id, ['quantity' => '500'])->assertRedirect();
        $this->postAs($b['user'], 'buyer/cart/checkout', ['delivery_country' => 'IN', 'delivery_address' => 'Delhi', 'logistics_mode' => 'platform_managed', 'accept_terms' => '1'])->assertRedirect();
        $order = model(OrderModel::class)->where('buyer_company_id', $b['company']['id'])->first();
        $this->assertSame('pending_supplier_confirmation', $order['status']);
        $this->assertSame(4000.0, (float) $order['total']);
        $this->assertSame(500, (int) model(ProductModel::class)->find($p['id'])['stock_reserved']);

        // Supplier confirms → proforma invoice → awaiting payment.
        $this->postAs($s['user'], "supplier/orders/{$order['id']}/status", ['to' => 'confirmed'])->assertRedirect();
        $order = model(OrderModel::class)->find($order['id']);
        $this->assertSame('awaiting_payment', $order['status']);
        $this->assertSame(1, db_connect()->table('invoices')->where('reference_type', 'order')->where('reference_id', $order['id'])->where('invoice_type', 'proforma')->countAllResults());
    }
}
