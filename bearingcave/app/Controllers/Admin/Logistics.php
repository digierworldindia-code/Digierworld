<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\CompanyModel;
use App\Models\LogisticsRequestModel;
use App\Models\ShipmentModel;
use App\Services\LogisticsService;

class Logistics extends AdminController
{
    public function index(): string
    {
        $m = model(LogisticsRequestModel::class)->select('logistics_requests.*, companies.legal_name')->join('companies', 'companies.id = logistics_requests.company_id');
        if ($s = $this->request->getGet('status')) {
            $m->where('logistics_requests.status', $s);
        }
        if ($mode = $this->request->getGet('mode')) {
            $m->where('logistics_requests.mode', $mode);
        }
        $m->orderBy('logistics_requests.id', 'DESC');

        return $this->page('logistics', ['title' => 'Logistics', 'rows' => $m->paginate(25), 'pager' => $m->pager, 'statuses' => LogisticsService::STATUSES, 'modes' => LogisticsService::MODES]);
    }

    private function req(int $id): array
    {
        return model(LogisticsRequestModel::class)->find($id) ?? $this->notFound();
    }

    public function show(int $id): string
    {
        $r = $this->req($id);
        $shipments = service('logistics')->shipments($id);
        $events = [];
        foreach ($shipments as $s) {
            $events[$s['id']] = service('logistics')->events((int) $s['id']);
        }
        $group = $r['consolidation_ref'] ? model(LogisticsRequestModel::class)->where('consolidation_ref', $r['consolidation_ref'])->where('id !=', $id)->findAll() : [];

        return $this->page('logistics_show', ['title' => 'Logistics ' . $r['request_number'], 'r' => $r, 'company' => model(CompanyModel::class)->find($r['company_id']),
            'shipments' => $shipments, 'events' => $events, 'group' => $group,
            'statuses' => LogisticsService::STATUSES, 'modes' => LogisticsService::MODES, 'shipmentStatuses' => LogisticsService::SHIPMENT_STATUSES,
            'estimate' => service('logistics')->estimate($r['mode'], $r['goods_value'] !== null ? (float) $r['goods_value'] : null)]);
    }

    public function quote(int $id)
    {
        $r = $this->req($id);
        if ($v = $this->invalid(['quoted_fee' => 'required|decimal|greater_than[0]'])) {
            return $v;
        }

        return $this->attempt(fn () => service('logistics')->quote($r, (float) $this->request->getPost('quoted_fee'), $this->request->getPost('fee_basis') ?: null), 'Quotation sent.');
    }

    public function book(int $id)
    {
        $r = $this->req($id);

        return $this->attempt(fn () => service('logistics')->book($r, $this->request->getPost(['carrier', 'tracking_number', 'transport_mode', 'eta', 'location']), $this->userId()), 'Shipment booked.');
    }

    public function updateShipment(int $shipmentId)
    {
        $s = model(ShipmentModel::class)->find($shipmentId) ?? $this->notFound();

        return $this->attempt(fn () => service('logistics')->updateShipment($s, (string) $this->request->getPost('status'), $this->request->getPost('location') ?: null, $this->request->getPost('description') ?: null, $this->userId()), 'Tracking updated.');
    }
}
