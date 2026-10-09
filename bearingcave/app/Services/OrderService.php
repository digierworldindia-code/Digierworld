<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\CompanyModel;
use App\Models\InvoiceModel;
use App\Models\OrderItemModel;
use App\Models\OrderModel;
use App\Models\OrderStatusHistoryModel;
use App\Models\ProductModel;

/**
 * Purchase orders. Orders are only created from approved commercial terms:
 * an accepted supplier quotation, or a listed price that the supplier then
 * confirms. Stock is reserved atomically at order creation and released on
 * cancellation / dispatched on shipment.
 */
class OrderService
{
    public const STATUSES = [
        'pending_supplier_confirmation' => 'Awaiting supplier confirmation',
        'confirmed'      => 'Confirmed',
        'awaiting_payment' => 'Awaiting payment',
        'paid'           => 'Paid',
        'in_fulfilment'  => 'In fulfilment',
        'shipped'        => 'Shipped',
        'delivered'      => 'Delivered',
        'completed'      => 'Completed',
        'cancelled'      => 'Cancelled',
        'disputed'       => 'Disputed',
    ];

    /** Allowed transitions: from => [to => who] (who: buyer|supplier|staff|system) */
    public const TRANSITIONS = [
        'pending_supplier_confirmation' => ['confirmed' => ['supplier', 'staff'], 'cancelled' => ['supplier', 'buyer', 'staff']],
        'confirmed'        => ['awaiting_payment' => ['system', 'staff'], 'cancelled' => ['buyer', 'supplier', 'staff']],
        'awaiting_payment' => ['paid' => ['system', 'staff'], 'cancelled' => ['buyer', 'staff']],
        'paid'             => ['in_fulfilment' => ['supplier', 'staff']],
        'in_fulfilment'    => ['shipped' => ['supplier', 'staff', 'system']],
        'shipped'          => ['delivered' => ['buyer', 'staff', 'system']],
        'delivered'        => ['completed' => ['buyer', 'staff', 'system'], 'disputed' => ['system']],
        'completed'        => ['disputed' => ['system']],
        'disputed'         => ['completed' => ['staff', 'system'], 'delivered' => ['staff', 'system']],
    ];

    public function __construct(
        private OrderModel $orders = new OrderModel(),
        private OrderItemModel $items = new OrderItemModel(),
    ) {
    }

    public function createFromQuotation(array $q, array $rfq, array $buyer, int $userId, array $delivery): array
    {
        $qitems = service('quotations')->items((int) $q['id']);
        $id     = (int) $this->orders->insert($this->header($buyer, (int) $q['supplier_company_id'], $userId, $delivery) + [
            'quotation_id'     => $q['id'],
            'rfq_id'           => $rfq['id'],
            'source'           => 'quotation',
            'status'           => 'confirmed',
            'currency'         => $q['currency'],
            'incoterm'         => $q['delivery_terms'] ?: ($rfq['delivery_terms'] ?? null),
            'payment_terms'    => $q['payment_terms'],
            'logistics_mode'   => $q['logistics_option'],
            'commercial_terms' => "Quotation {$q['quotation_number']} (revision {$q['revision']}) accepted by buyer on " . date('d M Y H:i') . '. Lead time: ' . ($q['lead_time_days'] ?? '—') . ' days. ' . ($q['notes'] ?? ''),
            'buyer_confirmed_at' => date('Y-m-d H:i:s'),
            'supplier_confirmed_at' => $q['submitted_at'],
        ]);
        $subtotal = 0.0;
        foreach ($qitems as $qi) {
            $reserved = 0;
            if ($qi['product_id']) {
                service('inventory')->reserve((int) $qi['product_id'], (int) $qi['quantity'], 'order', $id);
                $reserved = 1;
            }
            $this->items->insert([
                'order_id' => $id, 'product_id' => $qi['product_id'], 'quotation_item_id' => $qi['id'], 'description' => $qi['description'],
                'part_number' => $qi['part_number'], 'quantity' => $qi['quantity'], 'unit_price' => $qi['unit_price'], 'line_total' => $qi['line_total'], 'stock_reserved' => $reserved,
            ]);
            $subtotal += (float) $qi['line_total'];
        }
        $this->orders->update($id, ['subtotal' => $subtotal, 'total' => $subtotal]);
        $this->history($id, null, 'confirmed', $userId, 'Created from accepted quotation ' . $q['quotation_number']);
        $order = $this->orders->find($id);
        $this->issueProforma($order);

        return $this->orders->find($id);
    }

    /**
     * Creates one order per supplier from the buyer's basket at listed prices.
     * All reservations happen in one transaction: either every line is
     * reserved or nothing is.
     *
     * @return list<array> created orders
     */
    public function createFromCart(array $cart, array $buyer, int $userId, array $delivery): array
    {
        $lines = service('cart')->lines($cart);
        if ($lines === []) {
            throw new BusinessRuleException('Your basket is empty.');
        }
        $bySupplier = [];
        foreach ($lines as $l) {
            if ($l['price']['total'] === null) {
                throw new BusinessRuleException("\"{$l['product']['name']}\" is price-on-request. Request a quotation for it instead of ordering directly.");
            }
            service('products')->assertOrderableQuantity($l['product'], (int) $l['item']['quantity']);
            $bySupplier[(int) $l['product']['company_id']][] = $l;
        }

        $db      = db_connect();
        $created = [];
        $db->transBegin();

        try {
            foreach ($bySupplier as $supplierId => $sLines) {
                $currency = $sLines[0]['product']['currency'];
                foreach ($sLines as $l) {
                    if ($l['product']['currency'] !== $currency) {
                        throw new BusinessRuleException('Items from one supplier must share a currency; please order them separately.');
                    }
                }
                $id = (int) $this->orders->insert($this->header($buyer, $supplierId, $userId, $delivery) + [
                    'source'             => 'cart',
                    'status'             => 'pending_supplier_confirmation',
                    'currency'           => $currency,
                    'commercial_terms'   => 'Order at listed marketplace prices on ' . date('d M Y H:i') . '. Subject to supplier confirmation.',
                    'buyer_confirmed_at' => date('Y-m-d H:i:s'),
                ]);
                $subtotal = 0.0;
                foreach ($sLines as $l) {
                    $qty = (int) $l['item']['quantity'];
                    service('inventory')->reserve((int) $l['product']['id'], $qty, 'order', $id);
                    $this->items->insert([
                        'order_id' => $id, 'product_id' => $l['product']['id'], 'description' => $l['product']['name'], 'part_number' => $l['product']['part_number'],
                        'quantity' => $qty, 'unit_price' => $l['price']['unit'], 'line_total' => $l['price']['total'], 'stock_reserved' => 1,
                    ]);
                    $subtotal += (float) $l['price']['total'];
                }
                $this->orders->update($id, ['subtotal' => $subtotal, 'total' => $subtotal]);
                $this->history($id, null, 'pending_supplier_confirmation', $userId, 'Placed from basket');
                $created[] = $this->orders->find($id);
            }
            service('cart')->clear($cart);
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        foreach ($created as $o) {
            service('notifications')->notifyCompany((int) $o['supplier_company_id'], 'order.new', "New order {$o['order_number']}", 'Please confirm availability and terms.', 'supplier/orders/' . $o['id']);
            service('audit')->log('order.created', ['entity_type' => 'order', 'entity_id' => (int) $o['id'], 'company_id' => (int) $buyer['id'], 'description' => $o['order_number']]);
        }

        return $created;
    }

    private function header(array $buyer, int $supplierId, int $userId, array $delivery): array
    {
        $mode = $delivery['logistics_mode'] ?? 'platform_managed';
        if (! in_array($mode, ['platform_managed', 'supplier_direct', 'confidential', 'buyer_arranged'], true)) {
            $mode = 'platform_managed';
        }

        return [
            'order_number'        => service('numbers')->next('PO'),
            'buyer_company_id'    => $buyer['id'],
            'supplier_company_id' => $supplierId,
            'delivery_country'    => $delivery['delivery_country'] ?? $buyer['country_code'],
            'delivery_address'    => $delivery['delivery_address'] ?? null,
            'buyer_po_reference'  => $delivery['buyer_po_reference'] ?? null,
            'incoterm'            => $delivery['incoterm'] ?? null,
            'logistics_mode'      => $mode,
            'created_by'          => $userId,
        ];
    }

    public function items(int $orderId): array
    {
        return $this->items->where('order_id', $orderId)->findAll();
    }

    public function canTransition(array $order, string $to, string $actor): bool
    {
        return in_array($actor, self::TRANSITIONS[$order['status']][$to] ?? [], true);
    }

    /**
     * Moves an order through its lifecycle with stock side effects.
     */
    public function transition(array $order, string $to, string $actor, ?int $userId, ?string $note = null): array
    {
        if (! $this->canTransition($order, $to, $actor)) {
            throw new BusinessRuleException('This order cannot move from "' . (self::STATUSES[$order['status']] ?? $order['status']) . '" to "' . (self::STATUSES[$to] ?? $to) . '".');
        }
        if ($to === 'cancelled' && trim((string) $note) === '') {
            throw new BusinessRuleException('Please give a cancellation reason.');
        }
        $db = db_connect();
        $db->transBegin();

        try {
            $update = ['status' => $to];
            if ($to === 'cancelled') {
                foreach ($this->items((int) $order['id']) as $it) {
                    if ($it['stock_reserved'] && $it['product_id']) {
                        service('inventory')->release((int) $it['product_id'], (int) $it['quantity'], 'order', (int) $order['id'], 'Order cancelled');
                        $this->items->update($it['id'], ['stock_reserved' => 0]);
                    }
                }
                $update['cancelled_reason'] = $note;
                $db->table('invoices')->where('reference_type', 'order')->where('reference_id', $order['id'])->where('status', 'issued')->update(['status' => 'void']);
            }
            if ($to === 'confirmed') {
                $update['supplier_confirmed_at'] = date('Y-m-d H:i:s');
            }
            if ($to === 'shipped') {
                foreach ($this->items((int) $order['id']) as $it) {
                    if ($it['stock_reserved'] && $it['product_id']) {
                        service('inventory')->dispatch((int) $it['product_id'], (int) $it['quantity'], 'order', (int) $order['id']);
                        $this->items->update($it['id'], ['stock_reserved' => 0]);
                    }
                }
                $update['shipment_status'] = 'in_transit';
            }
            if ($to === 'delivered') {
                $update['shipment_status'] = 'delivered';
            }
            if ($to === 'paid') {
                $update['payment_status'] = 'paid';
            }
            $this->orders->update($order['id'], $update);
            $this->history((int) $order['id'], $order['status'], $to, $userId, $note);
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        $order = $this->orders->find($order['id']);
        if ($to === 'confirmed') {
            $this->issueProforma($order);
            $order = $this->orders->find($order['id']);
        }

        $msg = 'Order ' . $order['order_number'] . ' is now: ' . self::STATUSES[$order['status']] . ($note ? " — {$note}" : '');
        service('notifications')->notifyCompany((int) $order['buyer_company_id'], 'order.updated', $msg, '', 'buyer/orders/' . $order['id']);
        service('notifications')->notifyCompany((int) $order['supplier_company_id'], 'order.updated', $msg, '', 'supplier/orders/' . $order['id']);
        service('audit')->log('order.status', ['entity_type' => 'order', 'entity_id' => (int) $order['id'], 'description' => $msg]);

        return $order;
    }

    /**
     * Issues the proforma invoice for a confirmed order and moves it to
     * awaiting_payment.
     */
    public function issueProforma(array $order): void
    {
        if ($order['status'] !== 'confirmed') {
            return;
        }
        $exists = model(InvoiceModel::class)->where('reference_type', 'order')->where('reference_id', $order['id'])->whereIn('status', ['issued', 'paid'])->first();
        if (! $exists) {
            service('billing')->issueInvoice((int) $order['buyer_company_id'], 'proforma', $order['currency'], (float) $order['total'], 'order', (int) $order['id'], 'Proforma invoice for order ' . $order['order_number']);
        }
        $this->orders->update($order['id'], ['status' => 'awaiting_payment', 'payment_status' => 'unpaid']);
        $this->history((int) $order['id'], 'confirmed', 'awaiting_payment', null, 'Proforma invoice issued');
    }

    public function markPaid(int $orderId): void
    {
        $order = $this->orders->find($orderId);
        if ($order && $order['status'] === 'awaiting_payment') {
            $this->transition($order, 'paid', 'system', null, 'Payment confirmed');
        }
    }

    /**
     * Adds service fees (inspection/logistics) to an order total.
     */
    public function addFee(int $orderId, string $type, float $amount): void
    {
        $col = $type === 'inspection' ? 'inspection_fee' : 'logistics_fee';
        db_connect()->query("UPDATE orders SET {$col} = {$col} + ? WHERE id = ?", [$amount, $orderId]);
    }

    public function history(int $orderId, ?string $from, string $to, ?int $userId, ?string $note): void
    {
        model(OrderStatusHistoryModel::class)->insert(['order_id' => $orderId, 'from_status' => $from, 'to_status' => $to, 'user_id' => $userId, 'note' => $note]);
    }

    /**
     * Returns the order if the company is a party to it; otherwise logs a
     * security event and returns null.
     */
    public function forParty(int $orderId, int $companyId, string $side): ?array
    {
        $order = $this->orders->find($orderId);
        $col   = $side === 'buyer' ? 'buyer_company_id' : 'supplier_company_id';
        if (! $order || (int) $order[$col] !== $companyId) {
            service('audit')->security('access.denied', "Company #{$companyId} tried to open order #{$orderId}", ['entity_type' => 'order', 'entity_id' => $orderId, 'company_id' => $companyId]);

            return null;
        }

        return $order;
    }
}
