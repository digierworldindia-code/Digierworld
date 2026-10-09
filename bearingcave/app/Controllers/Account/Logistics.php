<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Models\LogisticsRequestModel;
use App\Models\OrderModel;
use App\Models\ShipmentModel;
use App\Services\LogisticsService;

class Logistics extends AreaController
{
    public function index(): string
    {
        $cid = $this->cid();
        $m   = model(LogisticsRequestModel::class)->groupStart()->where('company_id', $cid)
            ->orWhere("order_id IN (SELECT id FROM orders WHERE buyer_company_id = {$cid} OR supplier_company_id = {$cid})", null, false)->groupEnd()->orderBy('id', 'DESC');

        return $this->page('account/logistics', ['title' => 'Logistics', 'rows' => $m->paginate(20), 'pager' => $m->pager, 'statuses' => LogisticsService::STATUSES, 'modes' => LogisticsService::MODES]);
    }

    public function new(): string
    {
        $col    = $this->area() === 'buyer' ? 'buyer_company_id' : 'supplier_company_id';
        $orders = model(OrderModel::class)->where($col, $this->cid())->whereIn('status', ['confirmed', 'awaiting_payment', 'paid', 'in_fulfilment'])->orderBy('id', 'DESC')->findAll(50);
        $modes  = LogisticsService::MODES;
        if (! entitled('consolidation_services')) {
            unset($modes['consolidation']);
        }
        if ($this->area() === 'supplier' && ! entitled('direct_logistics')) {
            unset($modes['supplier_direct']);
        }

        return $this->page('account/logistics_new', ['title' => 'Request logistics', 'orders' => $orders, 'modes' => $modes, 'orderId' => (int) $this->request->getGet('order_id') ?: null,
            'estimate' => service('logistics')->estimate('platform_managed', null), 'consolidationPolicy' => policy('logistics.consolidation_policy')]);
    }

    public function create()
    {
        if ($r = $this->invalid(['mode' => 'required', 'destination_country' => 'required|exact_length[2]', 'pickup_country' => 'permit_empty|exact_length[2]', 'weight_kg' => 'permit_empty|decimal',
            'volume_cbm' => 'permit_empty|decimal', 'packages' => 'permit_empty|is_natural', 'goods_value' => 'permit_empty|decimal', 'order_id' => 'permit_empty|is_natural_no_zero'])) {
            return $r;
        }
        $req = null;
        $res = $this->attempt(function () use (&$req) {
            $req = service('logistics')->create($this->company(), $this->userId(), $this->request->getPost());
        }, 'Logistics requested.');

        return $req ? redirect()->to(site_url($this->area() . '/logistics/' . $req['id']))->with('success', 'Logistics request ' . $req['request_number'] . ' created.') : $res;
    }

    private function load(int $id): array
    {
        $r   = model(LogisticsRequestModel::class)->find($id);
        $cid = $this->cid();
        $ok  = $r && ((int) $r['company_id'] === $cid || ($r['order_id'] && service('orders')->forParty((int) $r['order_id'], $cid, $this->area())));
        if (! $ok) {
            service('audit')->security('access.denied', "Company #{$cid} tried to open logistics #{$id}", ['entity_type' => 'logistics_request', 'entity_id' => $id, 'company_id' => $cid]);
            $this->notFound();
        }

        return $r;
    }

    public function show(int $id): string
    {
        $r        = $this->load($id);
        $shipments = service('logistics')->shipments($id);
        $events   = [];
        foreach ($shipments as $s) {
            $events[$s['id']] = service('logistics')->events((int) $s['id']);
        }

        return $this->page('account/logistics_show', [
            'title' => 'Logistics ' . $r['request_number'], 'r' => service('logistics')->presentFor($r, $this->company()), 'shipments' => $shipments, 'events' => $events,
            'statuses' => LogisticsService::STATUSES, 'modes' => LogisticsService::MODES, 'shipmentStatuses' => LogisticsService::SHIPMENT_STATUSES,
            'isRequester' => (int) $r['company_id'] === $this->cid(),
            'canBook' => $r['mode'] === 'supplier_direct' && $this->area() === 'supplier' && entitled('direct_logistics'),
        ]);
    }

    public function respond(int $id)
    {
        $r = $this->load($id);
        if ((int) $r['company_id'] !== $this->cid()) {
            $this->notFound();
        }
        $accept = $this->request->getPost('decision') === 'accept';

        return $this->attempt(fn () => service('logistics')->respond($r, $accept), $accept ? 'Logistics quotation accepted.' : 'Quotation declined.');
    }

    /** Supplier-direct shipping: the supplier books and tracks its own shipment. */
    public function book(int $id)
    {
        $r = $this->load($id);
        if ($r['mode'] !== 'supplier_direct' || $this->area() !== 'supplier' || ! entitled('direct_logistics')) {
            return redirect()->back()->with('error', 'Only supplier-direct shipments can be booked by the supplier.');
        }

        return $this->attempt(fn () => service('logistics')->book($r, $this->request->getPost(['carrier', 'tracking_number', 'transport_mode', 'eta', 'location']), $this->userId()), 'Shipment booked.');
    }

    public function updateShipment(int $shipmentId)
    {
        $s = model(ShipmentModel::class)->find($shipmentId);
        if (! $s || ! $s['logistics_request_id']) {
            $this->notFound();
        }
        $r = $this->load((int) $s['logistics_request_id']);
        if ($r['mode'] !== 'supplier_direct' || $this->area() !== 'supplier') {
            return redirect()->back()->with('error', 'Tracking for this shipment is updated by BearingCave logistics.');
        }

        return $this->attempt(fn () => service('logistics')->updateShipment($s, (string) $this->request->getPost('status'), $this->request->getPost('location') ?: null, $this->request->getPost('description') ?: null, $this->userId()), 'Shipment updated.');
    }
}
