<?php

declare(strict_types=1);

namespace App\Controllers\Buyer;

use App\Controllers\BaseController;
use App\Models\ProductModel;

class Cart extends BaseController
{
    private function cart(): array
    {
        return service('cart')->cart($this->userId(), (int) service('companyContext')->companyId());
    }

    public function index(): string
    {
        $lines  = service('cart')->lines($this->cart());
        $groups = [];
        foreach ($lines as $l) {
            $groups[$l['product']['company_id']][] = $l;
        }

        return view('buyer/cart', ['title' => 'Cart / RFQ basket', 'area' => 'buyer', 'groups' => $groups, 'count' => count($lines)]);
    }

    public function add(int $productId)
    {
        $p = model(ProductModel::class)->find($productId);
        if (! $p) {
            $this->notFound();
        }

        return $this->attempt(fn () => service('cart')->add($this->cart(), $p, (int) $this->request->getPost('quantity')), 'Added to your basket.', 'buyer/cart');
    }

    public function update(int $itemId)
    {
        return $this->attempt(fn () => service('cart')->update($this->cart(), $itemId, (int) $this->request->getPost('quantity')), 'Basket updated.');
    }

    public function remove(int $itemId)
    {
        return $this->attempt(fn () => service('cart')->remove($this->cart(), $itemId), 'Item removed.');
    }

    /**
     * Converts the basket into an RFQ (useful for price-on-request items or negotiating).
     */
    public function toRfq()
    {
        $cart  = $this->cart();
        $lines = service('cart')->lines($cart);
        if (! $lines) {
            return redirect()->back()->with('error', 'Your basket is empty.');
        }
        $items = array_map(static fn ($l) => [
            'product_id' => (int) $l['product']['id'], 'category_id' => (int) $l['product']['category_id'], 'brand_id' => $l['product']['brand_id'],
            'part_number' => $l['product']['part_number'], 'description' => $l['product']['name'], 'quantity' => (int) $l['item']['quantity'], 'unit' => $l['product']['unit_of_measure'],
        ], $lines);
        $c   = $this->company();
        $rfq = null;
        $res = $this->attempt(function () use ($c, $items, $cart, &$rfq) {
            $rfq = service('rfqs')->create($c, $this->userId(), [
                'title' => 'Basket RFQ (' . count($items) . ' item' . (count($items) > 1 ? 's' : '') . ')', 'destination_country' => $c['country_code'], 'currency' => 'INR',
                'deadline_at' => date('Y-m-d H:i:s', strtotime('+' . (int) policy('rfq.default_deadline_days', 7) . ' days')),
            ], $items, 'cart');
            service('cart')->clear($cart);
        }, 'RFQ created from basket.');

        return $rfq ? redirect()->to(site_url('buyer/rfqs/' . $rfq['id']))->with('success', 'RFQ ' . $rfq['rfq_number'] . ' created from your basket.') : $res;
    }

    public function checkout()
    {
        $lines = service('cart')->lines($this->cart());
        if (! $lines) {
            return redirect()->to(site_url('buyer/cart'))->with('error', 'Your basket is empty.');
        }

        return view('buyer/checkout', ['title' => 'Place order', 'area' => 'buyer', 'lines' => $lines, 'company' => $this->company(),
            'escrow' => service('billing')->escrowAvailable(), 'refundPolicy' => policy('billing.refund_policy'), 'warranty' => policy('legal.warranty_terms')]);
    }

    public function placeOrder()
    {
        if ($r = $this->invalid(['delivery_country' => 'required|exact_length[2]', 'delivery_address' => 'required|max_length[1000]', 'logistics_mode' => 'required|in_list[platform_managed,confidential,buyer_arranged,supplier_direct]', 'accept_terms' => 'required', 'buyer_po_reference' => 'permit_empty|max_length[80]', 'incoterm' => 'permit_empty|max_length[20]'])) {
            return $r;
        }
        $orders = [];
        $res    = $this->attempt(function () use (&$orders) {
            $orders = service('orders')->createFromCart($this->cart(), $this->company(), $this->userId(), $this->request->getPost(['delivery_country', 'delivery_address', 'logistics_mode', 'buyer_po_reference', 'incoterm']));
        }, 'Order placed.');

        if ($orders) {
            $nums = implode(', ', array_column($orders, 'order_number'));

            return redirect()->to(site_url(count($orders) === 1 ? 'buyer/orders/' . $orders[0]['id'] : 'buyer/orders'))->with('success', "Order(s) {$nums} placed. Stock has been reserved; each supplier must confirm before a proforma invoice is issued.");
        }

        return $res;
    }
}
