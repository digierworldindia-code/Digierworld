<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Models\InspectionRequestModel;
use App\Models\OrderModel;
use App\Services\InspectionService;

class Inspections extends AreaController
{
    public function index(): string
    {
        $cid = $this->cid();
        // Own requests + requests made by the counterparty on our orders.
        $m = model(InspectionRequestModel::class)->groupStart()->where('company_id', $cid)
            ->orWhere("order_id IN (SELECT id FROM orders WHERE buyer_company_id = {$cid} OR supplier_company_id = {$cid})", null, false)->groupEnd()->orderBy('id', 'DESC');

        return $this->page('account/inspections', ['title' => 'Inspection', 'rows' => $m->paginate(20), 'pager' => $m->pager, 'statuses' => InspectionService::STATUSES]);
    }

    public function new(): string
    {
        $col    = $this->area() === 'buyer' ? 'buyer_company_id' : 'supplier_company_id';
        $orders = model(OrderModel::class)->where($col, $this->cid())->whereNotIn('status', ['cancelled', 'completed'])->orderBy('id', 'DESC')->findAll(50);

        return $this->page('account/inspection_new', [
            'title' => 'Request inspection', 'orders' => $orders, 'scopes' => InspectionService::SCOPES,
            'pct' => (float) policy('inspection.in_house_rate_pct', 10), 'min' => (float) policy('inspection.in_house_min_fee', 0),
            'productId' => (int) $this->request->getGet('product_id') ?: null, 'orderId' => (int) $this->request->getGet('order_id') ?: null,
        ]);
    }

    public function create()
    {
        if ($r = $this->invalid(['inspection_type' => 'required|in_list[in_house,third_party]', 'location_country' => 'required|exact_length[2]', 'location_city' => 'permit_empty|max_length[100]',
            'invoice_value' => 'permit_empty|decimal', 'shipment_volume_cbm' => 'permit_empty|decimal', 'order_id' => 'permit_empty|is_natural_no_zero', 'product_id' => 'permit_empty|is_natural_no_zero', 'notes' => 'permit_empty|max_length[2000]'])) {
            return $r;
        }
        $req = null;
        $res = $this->attempt(function () use (&$req) {
            $req = service('inspections')->create($this->company(), $this->userId(), $this->request->getPost());
        }, 'Inspection requested.');

        return $req ? redirect()->to(site_url($this->area() . '/inspections/' . $req['id']))->with('success', 'Inspection request ' . $req['request_number'] . ' created. You will receive a quotation.') : $res;
    }

    private function load(int $id): array
    {
        $r = model(InspectionRequestModel::class)->find($id);
        $cid = $this->cid();
        $ok = $r && ((int) $r['company_id'] === $cid || ($r['order_id'] && service('orders')->forParty((int) $r['order_id'], $cid, $this->area())));
        if (! $ok) {
            service('audit')->security('access.denied', "Company #{$cid} tried to open inspection #{$id}", ['entity_type' => 'inspection_request', 'entity_id' => $id, 'company_id' => $cid]);
            $this->notFound();
        }

        return $r;
    }

    public function show(int $id): string
    {
        $r = $this->load($id);

        return $this->page('account/inspection', [
            'title' => 'Inspection ' . $r['request_number'], 'r' => $r, 'report' => service('inspections')->report($id),
            'documents' => service('documents')->forEntity('inspection_request', $id), 'statuses' => InspectionService::STATUSES, 'scopes' => InspectionService::SCOPES,
            'isRequester' => (int) $r['company_id'] === $this->cid(),
        ]);
    }

    public function respond(int $id)
    {
        $r = $this->load($id);
        if ((int) $r['company_id'] !== $this->cid()) {
            $this->notFound();
        }
        $accept = $this->request->getPost('decision') === 'accept';

        return $this->attempt(fn () => service('inspections')->respond($r, $accept), $accept ? 'Quotation accepted. An invoice has been issued and an inspector will be assigned.' : 'Quotation declined.');
    }
}
