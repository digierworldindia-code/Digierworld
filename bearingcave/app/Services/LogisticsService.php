<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\LogisticsRequestModel;
use App\Models\OrderModel;
use App\Models\ShipmentEventModel;
use App\Models\ShipmentModel;

/**
 * Logistics requests and shipment tracking.
 *
 * The spreadsheet mentions an optional additional 10% BearingCave-managed
 * logistics charge, but not its base. The base is configurable
 * (logistics.platform_fee_basis) and defaults to "manual_quote" — no
 * automatic charge on invoice value — until the client approves it.
 *
 * Confidential shipping hides the supplier's pickup address and identity
 * from the buyer; BearingCave appears as the shipper of record.
 */
class LogisticsService
{
    public const MODES = [
        'platform_managed' => 'BearingCave-managed shipping',
        'supplier_direct'  => 'Supplier-direct shipping',
        'confidential'     => 'Confidential shipping (supplier identity hidden)',
        'consolidation'    => 'Multi-supplier consolidation',
    ];

    public const STATUSES = [
        'requested' => 'Requested', 'quoted' => 'Quoted', 'accepted' => 'Quote accepted', 'booked' => 'Booked',
        'in_transit' => 'In transit', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled',
    ];

    public const SHIPMENT_STATUSES = [
        'booked' => 'Booked', 'picked_up' => 'Picked up', 'in_transit' => 'In transit', 'customs' => 'Customs clearance',
        'out_for_delivery' => 'Out for delivery', 'delivered' => 'Delivered', 'exception' => 'Exception',
    ];

    public function __construct(private LogisticsRequestModel $requests = new LogisticsRequestModel())
    {
    }

    /**
     * @return array{fee:float|null,basis:string}
     */
    public function estimate(string $mode, ?float $goodsValue, ?float $freightCost = null): array
    {
        if ($mode === 'supplier_direct') {
            return ['fee' => null, 'basis' => 'Supplier-direct shipping is arranged and priced by the supplier.'];
        }
        $pct   = (float) policy('logistics.platform_fee_pct', 10);
        $basis = (string) policy('logistics.platform_fee_basis', 'manual_quote');

        return match ($basis) {
            'goods_value' => $goodsValue
                ? ['fee' => round($goodsValue * $pct / 100, 2), 'basis' => "Platform logistics charge {$pct}% of goods value (estimate; freight quoted separately)."]
                : ['fee' => null, 'basis' => "Platform logistics charge {$pct}% of goods value — enter goods value for an estimate."],
            'freight_cost' => $freightCost
                ? ['fee' => round($freightCost * (1 + $pct / 100), 2), 'basis' => "Freight cost plus {$pct}% platform charge."]
                : ['fee' => null, 'basis' => "Freight cost plus {$pct}% platform charge — quoted after carrier pricing."],
            default => ['fee' => null, 'basis' => 'Quoted by the BearingCave logistics team (calculation base pending client approval).'],
        };
    }

    public function create(array $company, int $userId, array $data): array
    {
        $mode = $data['mode'] ?? '';
        if (! isset(self::MODES[$mode])) {
            throw new BusinessRuleException('Choose a shipping option.');
        }
        $ent = service('entitlements');
        if ($mode === 'consolidation' && ! $ent->has($company, 'consolidation_services')) {
            throw new BusinessRuleException('Multi-supplier consolidation is available to verified buyers.');
        }
        if ($mode === 'supplier_direct' && $company['company_type'] === 'supplier' && ! $ent->has($company, 'direct_logistics')) {
            throw new BusinessRuleException('Supplier-direct logistics is a Verified Supplier feature.');
        }
        if (empty($data['destination_country'])) {
            throw new BusinessRuleException('Destination country is required.');
        }
        $orderId = null;
        $value   = ($data['goods_value'] ?? '') !== '' ? (float) $data['goods_value'] : null;
        $currency = $data['currency'] ?? 'INR';
        if (! empty($data['order_id'])) {
            $order = service('orders')->forParty((int) $data['order_id'], (int) $company['id'], $company['company_type']);
            if (! $order) {
                throw new BusinessRuleException('Order not found.');
            }
            $orderId  = (int) $order['id'];
            $value    = (float) $order['subtotal'];
            $currency = $order['currency'];
        }
        $est = $this->estimate($mode, $value);
        $id  = (int) $this->requests->insert([
            'request_number'      => service('numbers')->next('LOG'),
            'company_id'          => $company['id'],
            'requested_by'        => $userId,
            'order_id'            => $orderId,
            'mode'                => $mode,
            'consolidation_ref'   => $mode === 'consolidation' ? (($data['consolidation_ref'] ?? '') ?: null) : null,
            'pickup_country'      => ($data['pickup_country'] ?? '') ?: null,
            'pickup_city'         => $data['pickup_city'] ?? null,
            'pickup_address'      => $data['pickup_address'] ?? null,
            'pickup_contact'      => $data['pickup_contact'] ?? null,
            'destination_country' => $data['destination_country'],
            'destination_city'    => $data['destination_city'] ?? null,
            'destination_address' => $data['destination_address'] ?? null,
            'destination_contact' => $data['destination_contact'] ?? null,
            'incoterm'            => $data['incoterm'] ?? null,
            'cargo_description'   => $data['cargo_description'] ?? null,
            'weight_kg'           => ($data['weight_kg'] ?? '') !== '' ? $data['weight_kg'] : null,
            'volume_cbm'          => ($data['volume_cbm'] ?? '') !== '' ? $data['volume_cbm'] : null,
            'packages'            => ($data['packages'] ?? '') !== '' ? (int) $data['packages'] : null,
            'goods_value'         => $value,
            'currency'            => $currency,
            'estimated_fee'       => $est['fee'],
            'fee_basis'           => $est['basis'],
            'status'              => 'requested',
            'notes'               => $data['notes'] ?? null,
        ]);
        if ($orderId) {
            model(OrderModel::class)->update($orderId, ['shipment_status' => 'requested', 'logistics_mode' => $mode === 'consolidation' ? 'platform_managed' : $mode]);
        }
        $req = $this->requests->find($id);
        service('notifications')->notifyStaff(['logistics_manager'], 'logistics.new', 'New logistics request ' . $req['request_number'], self::MODES[$mode], 'admin/logistics/' . $id);
        service('audit')->log('logistics.requested', ['entity_type' => 'logistics_request', 'entity_id' => $id, 'company_id' => (int) $company['id']]);

        return $req;
    }

    public function quote(array $req, float $fee, ?string $basis): void
    {
        $this->assertStatus($req, ['requested', 'quoted']);
        if ($fee <= 0) {
            throw new BusinessRuleException('Enter a fee greater than zero.');
        }
        $this->requests->update($req['id'], ['quoted_fee' => $fee, 'fee_basis' => $basis ?: $req['fee_basis'], 'status' => 'quoted']);
        service('notifications')->notifyCompany((int) $req['company_id'], 'logistics.quoted', 'Logistics quotation ' . $req['request_number'], $req['currency'] . ' ' . number_format($fee, 2), $this->ownerLink($req));
    }

    public function respond(array $req, bool $accept): void
    {
        $this->assertStatus($req, ['quoted']);
        $this->requests->update($req['id'], ['status' => $accept ? 'accepted' : 'cancelled']);
        if ($accept) {
            $inv = service('billing')->issueInvoice((int) $req['company_id'], 'logistics', $req['currency'], (float) $req['quoted_fee'], 'logistics_request', (int) $req['id'], 'Logistics ' . $req['request_number']);
            $this->requests->update($req['id'], ['invoice_id' => $inv['id']]);
            if ($req['order_id']) {
                service('orders')->addFee((int) $req['order_id'], 'logistics', (float) $req['quoted_fee']);
            }
        }
        service('notifications')->notifyStaff(['logistics_manager'], 'logistics.response', 'Logistics quote ' . ($accept ? 'accepted' : 'declined'), $req['request_number'], 'admin/logistics/' . $req['id']);
    }

    /**
     * Books a shipment (carrier + tracking). For supplier-direct shipping
     * the supplier may book; otherwise BearingCave logistics staff.
     */
    public function book(array $req, array $data, int $userId): array
    {
        $allowed = $req['mode'] === 'supplier_direct' ? ['requested', 'quoted', 'accepted'] : ['accepted'];
        $this->assertStatus($req, $allowed);
        if (trim((string) ($data['carrier'] ?? '')) === '') {
            throw new BusinessRuleException('Carrier is required.');
        }
        $id = (int) model(ShipmentModel::class)->insert([
            'logistics_request_id' => $req['id'], 'order_id' => $req['order_id'], 'carrier' => $data['carrier'],
            'tracking_number' => $data['tracking_number'] ?? null,
            'transport_mode' => in_array($data['transport_mode'] ?? '', ['air', 'sea', 'road', 'rail', 'courier'], true) ? $data['transport_mode'] : 'road',
            'status' => 'booked', 'eta' => ($data['eta'] ?? '') ?: null, 'created_by' => $userId,
        ]);
        $this->addEvent(['id' => $id], 'booked', $data['location'] ?? null, 'Shipment booked with ' . $data['carrier'], $userId);
        $this->requests->update($req['id'], ['status' => 'booked']);
        if ($req['order_id']) {
            model(OrderModel::class)->update($req['order_id'], ['shipment_status' => 'booked']);
        }
        service('notifications')->notifyCompany((int) $req['company_id'], 'shipment.update', 'Shipment booked ' . $req['request_number'], 'Carrier: ' . $data['carrier'] . (! empty($data['tracking_number']) ? ' · Tracking ' . $data['tracking_number'] : ''), $this->ownerLink($req));

        return model(ShipmentModel::class)->find($id);
    }

    public function updateShipment(array $shipment, string $status, ?string $location, ?string $description, int $userId): void
    {
        if (! isset(self::SHIPMENT_STATUSES[$status])) {
            throw new BusinessRuleException('Invalid shipment status.');
        }
        if ($shipment['status'] === 'delivered') {
            throw new BusinessRuleException('This shipment is already delivered.');
        }
        $update = ['status' => $status];
        if ($status === 'picked_up' || ($status === 'in_transit' && ! $shipment['shipped_at'])) {
            $update['shipped_at'] = date('Y-m-d H:i:s');
        }
        if ($status === 'delivered') {
            $update['delivered_at']      = date('Y-m-d H:i:s');
            $update['delivered_on_time'] = $shipment['eta'] ? (date('Y-m-d') <= $shipment['eta'] ? 1 : 0) : null;
        }
        model(ShipmentModel::class)->update($shipment['id'], $update);
        $this->addEvent($shipment, $status, $location, $description, $userId);

        $req = $shipment['logistics_request_id'] ? $this->requests->find($shipment['logistics_request_id']) : null;
        if ($req) {
            $this->requests->update($req['id'], ['status' => $status === 'delivered' ? 'delivered' : 'in_transit']);
        }
        if ($shipment['order_id'] && ($order = model(OrderModel::class)->find($shipment['order_id']))) {
            $orders = service('orders');
            if (in_array($status, ['picked_up', 'in_transit'], true) && $order['status'] === 'in_fulfilment') {
                $orders->transition($order, 'shipped', 'system', $userId, 'Shipment ' . self::SHIPMENT_STATUSES[$status]);
            } elseif ($status === 'delivered') {
                if ($order['status'] === 'in_fulfilment') {
                    $order = $orders->transition($order, 'shipped', 'system', $userId, 'Shipment dispatched');
                }
                if ($order['status'] === 'shipped') {
                    $orders->transition($order, 'delivered', 'system', $userId, 'Shipment delivered');
                }
            } else {
                $orders->history((int) $order['id'], $order['status'], $order['status'], $userId, 'Shipment: ' . self::SHIPMENT_STATUSES[$status] . ($location ? " ({$location})" : ''));
            }
            service('notifications')->notifyCompany((int) $order['buyer_company_id'], 'shipment.update', 'Shipment update: ' . self::SHIPMENT_STATUSES[$status], $order['order_number'] . ($location ? ' — ' . $location : ''), 'buyer/orders/' . $order['id']);
        } elseif ($req) {
            service('notifications')->notifyCompany((int) $req['company_id'], 'shipment.update', 'Shipment update: ' . self::SHIPMENT_STATUSES[$status], $req['request_number'], $this->ownerLink($req));
        }
    }

    private function addEvent(array $shipment, string $status, ?string $location, ?string $desc, int $userId): void
    {
        model(ShipmentEventModel::class)->insert(['shipment_id' => $shipment['id'], 'status' => $status, 'location' => $location, 'description' => $desc, 'event_at' => date('Y-m-d H:i:s'), 'created_by' => $userId]);
    }

    public function shipments(int $requestId): array
    {
        return model(ShipmentModel::class)->where('logistics_request_id', $requestId)->findAll();
    }

    public function events(int $shipmentId): array
    {
        return model(ShipmentEventModel::class)->where('shipment_id', $shipmentId)->orderBy('event_at', 'DESC')->findAll();
    }

    /**
     * Returns the request as the given company may see it. For confidential
     * shipments, buyers never receive pickup location/contact details.
     */
    public function presentFor(array $req, ?array $viewerCompany): array
    {
        $isStaff = service('companyContext')->isStaff();
        if ($isStaff || ! $req['order_id']) {
            return $req;
        }
        $order = model(OrderModel::class)->find($req['order_id']);
        $viewerIsBuyer = $viewerCompany && (int) $order['buyer_company_id'] === (int) $viewerCompany['id'];
        if ($viewerIsBuyer && in_array($req['mode'], ['confidential', 'platform_managed', 'consolidation'], true)) {
            $req['pickup_address'] = null;
            $req['pickup_contact'] = null;
            $req['pickup_city']    = $req['mode'] === 'confidential' ? null : $req['pickup_city'];
        }

        return $req;
    }

    private function assertStatus(array $req, array $allowed): void
    {
        if (! in_array($req['status'], $allowed, true)) {
            throw new BusinessRuleException('This action is not available while the request is "' . (self::STATUSES[$req['status']] ?? $req['status']) . '".');
        }
    }

    private function ownerLink(array $req): string
    {
        $c = model(\App\Models\CompanyModel::class)->find($req['company_id']);

        return ($c['company_type'] ?? 'buyer') . '/logistics/' . $req['id'];
    }
}
