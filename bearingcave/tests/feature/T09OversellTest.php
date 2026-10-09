<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Models\OrderModel;
use Tests\Support\BCTestCase;

/**
 * TEST 9: Buyer attempts to order more stock than available → backend
 * prevents overselling.
 */
final class T09OversellTest extends BCTestCase
{
    public function testOrderingMoreThanAvailableIsRejected(): void
    {
        $s = $this->verifiedSupplier();
        $p = $this->product($s['company'], [], 10);
        $b = $this->registerCompany('buyer');

        $this->postAs($b['user'], "buyer/cart/add/{$p['id']}", ['quantity' => '15'])->assertRedirect();
        $this->postAs($b['user'], 'buyer/cart/checkout', ['delivery_country' => 'IN', 'delivery_address' => 'X', 'logistics_mode' => 'platform_managed', 'accept_terms' => '1'])->assertRedirect();
        $this->assertStringContainsString('Only 10 unit(s)', (string) $this->flashError());
        $this->assertSame(0, model(OrderModel::class)->countAllResults());
        $row = db_connect()->table('products')->where('id', $p['id'])->get()->getRowArray();
        $this->assertSame(0, (int) $row['stock_reserved']);
        $this->assertSame(0, db_connect()->table('order_items')->countAllResults(), 'failed checkout rolled back completely');

        // Exactly the available quantity succeeds; any further reservation fails.
        $this->postAs($b['user'], 'buyer/cart/items/' . db_connect()->table('cart_items')->get()->getRow()->id, ['quantity' => '10'])->assertRedirect();
        $this->postAs($b['user'], 'buyer/cart/checkout', ['delivery_country' => 'IN', 'delivery_address' => 'X', 'logistics_mode' => 'platform_managed', 'accept_terms' => '1'])->assertRedirect();
        $this->assertSame(1, model(OrderModel::class)->countAllResults());
        $this->expectException(InsufficientStockException::class);
        service('inventory')->reserve((int) $p['id'], 1, 'test', 1);
    }

    public function testDatabaseConstraintBlocksNegativeOrOverReservedStock(): void
    {
        $s = $this->verifiedSupplier();
        $p = $this->product($s['company'], [], 5);
        $this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);
        db_connect()->query('UPDATE products SET stock_reserved = 6 WHERE id = ?', [$p['id']]);
    }
}
