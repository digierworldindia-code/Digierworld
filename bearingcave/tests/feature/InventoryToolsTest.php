<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProductModel;
use Tests\Support\BCTestCase;

/**
 * Workbook "Product Page Features": inventory liquidation tools, structured
 * tools for managing large inventory listings, real-time stock availability.
 */
final class InventoryToolsTest extends BCTestCase
{
    public function testBulkClearanceMarkdownIsReversibleAndVisibleToBuyers(): void
    {
        $s  = $this->verifiedSupplier();
        $p1 = $this->product($s['company'], ['sale_mode' => 'piece', 'unit_price' => '100']);
        $p2 = $this->product($s['company'], ['sale_mode' => 'piece', 'unit_price' => '50']);
        $other = $this->product($this->verifiedSupplier()['company']);
        db_connect()->table('pricing_tiers')->insert(['product_id' => $p1['id'], 'min_qty' => 100, 'max_qty' => null, 'unit_price' => '90.0000']);
        $before = $this->securityEvents('access.');

        // Another company's listing in the selection: nothing changes, security event logged.
        $this->postAs($s['user'], 'supplier/products/bulk', ['action' => 'clearance_start', 'clearance_pct' => '20', 'ids' => [(string) $p1['id'], (string) $other['id']]])->assertRedirect();
        $this->assertNull(model(ProductModel::class)->find($p1['id'])['clearance_started_at']);
        $this->assertGreaterThan($before, $this->securityEvents('access.'));

        $this->postAs($s['user'], 'supplier/products/bulk', ['action' => 'clearance_start', 'clearance_pct' => '20', 'ids' => [(string) $p1['id'], (string) $p2['id']]])->assertRedirect();
        $a = model(ProductModel::class)->find($p1['id']);
        $this->assertSame(80.0, (float) $a['unit_price']);
        $this->assertSame(100.0, (float) $a['pre_clearance_unit_price']);
        $this->assertSame(40.0, (float) model(ProductModel::class)->find($p2['id'])['unit_price']);
        $this->assertSame(72.0, service('products')->priceFor($a, 100)['unit'], 'tier price gets the same markdown');
        $this->assertSame(80.0, service('products')->priceFor($a, 10)['unit']);

        $buyer = $this->registerCompany('buyer');
        $page  = $this->getAs($buyer['user'], 'marketplace?clearance=1');
        $page->assertSee($a['name']);
        $this->assertStringNotContainsString($other['sku'], (string) $page->getBody());
        $this->getAs($buyer['user'], 'product/' . $a['slug'])->assertSee('marked this stock down by 20%');
        $api = json_decode((string) $this->getAs(null, 'api/v1/products?clearance=1')->getJSON(), true);
        $this->assertEquals(20, $api['data'][0]['clearance_pct'] ?? null);

        // Re-basing: a second markdown applies to the original price, not compounded.
        $this->postAs($s['user'], 'supplier/products/bulk', ['action' => 'clearance_start', 'clearance_pct' => '50', 'ids' => [(string) $p1['id']]])->assertRedirect();
        $this->assertSame(50.0, (float) model(ProductModel::class)->find($p1['id'])['unit_price']);

        $this->postAs($s['user'], 'supplier/products/bulk', ['action' => 'clearance_end', 'ids' => [(string) $p1['id'], (string) $p2['id']]])->assertRedirect();
        $a = model(ProductModel::class)->find($p1['id']);
        $this->assertSame(100.0, (float) $a['unit_price']);
        $this->assertNull($a['clearance_started_at']);
        $this->assertSame(50.0, (float) model(ProductModel::class)->find($p2['id'])['unit_price']);
        $this->assertSame(3, db_connect()->table('audit_logs')->whereIn('event', ['product.clearance_started', 'product.clearance_ended'])->where('entity_id', $p1['id'])->countAllResults());
    }

    public function testBulkStatusActionsReportFailuresAndStockEndpointRespectsVisibility(): void
    {
        $s  = $this->verifiedSupplier();
        $p1 = $this->product($s['company']);
        $p2 = $this->product($s['company']);
        // Reserved stock blocks archiving one listing; the other succeeds.
        service('inventory')->reserve((int) $p2['id'], 1, 'order', 999);

        $this->postAs($s['user'], 'supplier/products/bulk', ['action' => 'archive', 'ids' => [(string) $p1['id'], (string) $p2['id']]])->assertRedirect();
        $this->assertSame('archived', model(ProductModel::class)->find($p1['id'])['status']);
        $this->assertSame('published', model(ProductModel::class)->find($p2['id'])['status']);
        $this->assertStringContainsString('stock reserved', (string) $this->flashError());

        $res = $this->getAs(null, 'product/' . $p2['uuid'] . '/stock');
        $res->assertOK();
        $this->assertSame(99, json_decode((string) $res->getJSON(), true)['available']);

        model(ProductModel::class)->update($p2['id'], ['visibility' => 'verified_buyers']);
        $this->getAs(null, 'product/' . $p2['uuid'] . '/stock')->assertStatus(404);
    }
}
