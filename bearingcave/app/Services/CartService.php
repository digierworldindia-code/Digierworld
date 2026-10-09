<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\CartItemModel;
use App\Models\CartModel;
use App\Models\ProductModel;

/**
 * Buyer cart / RFQ basket. Items are re-checked against visibility on every
 * read, so a listing that becomes restricted disappears from the basket.
 */
class CartService
{
    public function __construct(
        private CartModel $carts = new CartModel(),
        private CartItemModel $items = new CartItemModel(),
    ) {
    }

    public function cart(int $userId, int $companyId): array
    {
        $cart = $this->carts->where('user_id', $userId)->where('status', 'active')->first();
        if (! $cart) {
            $id   = (int) $this->carts->insert(['user_id' => $userId, 'company_id' => $companyId, 'status' => 'active']);
            $cart = $this->carts->find($id);
        }

        return $cart;
    }

    public function add(array $cart, array $product, int $qty): void
    {
        if (! service('visibility')->canView($product)) {
            throw new BusinessRuleException('This listing is not available in your market.');
        }
        if ($qty < 1) {
            throw new BusinessRuleException('Quantity must be at least 1.');
        }
        $existing = $this->items->where('cart_id', $cart['id'])->where('product_id', $product['id'])->first();
        if ($existing) {
            $this->items->update($existing['id'], ['quantity' => $qty]);
        } else {
            $count = $this->items->where('cart_id', $cart['id'])->countAllResults();
            if ($count >= (int) policy('cart.max_items', 50)) {
                throw new BusinessRuleException('Your basket is full.');
            }
            $this->items->insert(['cart_id' => $cart['id'], 'product_id' => $product['id'], 'quantity' => $qty]);
        }
    }

    public function update(array $cart, int $itemId, int $qty): void
    {
        $item = $this->items->where('cart_id', $cart['id'])->where('id', $itemId)->first();
        if (! $item) {
            throw new BusinessRuleException('Item not found in your basket.');
        }
        if ($qty < 1) {
            $this->items->delete($item['id']);

            return;
        }
        $this->items->update($item['id'], ['quantity' => $qty]);
    }

    public function remove(array $cart, int $itemId): void
    {
        $this->items->where('cart_id', $cart['id'])->where('id', $itemId)->delete();
    }

    /**
     * Lines with live product data, price and availability. Invisible
     * listings are dropped.
     */
    public function lines(array $cart): array
    {
        $vis   = service('visibility');
        $ps    = service('products');
        $lines = [];
        foreach ($this->items->where('cart_id', $cart['id'])->findAll() as $it) {
            $p = model(ProductModel::class)->find($it['product_id']);
            if (! $p || ! $vis->canView($p)) {
                $this->items->delete($it['id']);

                continue;
            }
            $priceVisible = $vis->canSeePrice($p);
            $price        = $priceVisible ? $ps->priceFor($p, (int) $it['quantity']) : ['unit' => null, 'total' => null, 'mode' => 'on_request'];
            $lines[]      = [
                'item'      => $it,
                'product'   => $p,
                'supplier'  => model(\App\Models\CompanyModel::class)->find($p['company_id']),
                'price'     => $price,
                'available' => service('inventory')->available($p),
            ];
        }

        return $lines;
    }

    public function clear(array $cart, ?array $productIds = null): void
    {
        $q = $this->items->where('cart_id', $cart['id']);
        if ($productIds !== null) {
            $q->whereIn('product_id', $productIds ?: [0]);
        }
        $q->delete();
    }
}
