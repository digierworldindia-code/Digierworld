<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\CompanyModel;
use App\Models\OrderModel;
use App\Services\OrderService;

class Orders extends AdminController
{
    public function index(): string
    {
        $m = model(OrderModel::class)->select('orders.*, b.legal_name AS buyer, s.legal_name AS supplier')
            ->join('companies b', 'b.id = orders.buyer_company_id')->join('companies s', 's.id = orders.supplier_company_id');
        if ($st = $this->request->getGet('status')) {
            $m->where('orders.status', $st);
        }
        if ($q = trim((string) $this->request->getGet('q'))) {
            $m->groupStart()->like('orders.order_number', $q)->orLike('b.legal_name', $q)->orLike('s.legal_name', $q)->groupEnd();
        }
        $m->orderBy('orders.id', 'DESC');

        return $this->page('orders', ['title' => 'Orders', 'rows' => $m->paginate(25), 'pager' => $m->pager, 'statuses' => OrderService::STATUSES]);
    }

    public function show(int $id): string
    {
        $o   = model(OrderModel::class)->find($id) ?? $this->notFound();
        $db  = db_connect();
        $svc = service('orders');
        $actions = [];
        foreach (array_keys(OrderService::TRANSITIONS[$o['status']] ?? []) as $to) {
            if ($svc->canTransition($o, $to, 'staff') && $this->can('orders.manage')) {
                $actions[$to] = 'Move to: ' . OrderService::STATUSES[$to];
            }
        }
        $b = model(CompanyModel::class)->find($o['buyer_company_id']);
        $s = model(CompanyModel::class)->find($o['supplier_company_id']);

        return view('shared/order', [
            'area' => 'admin', 'side' => 'staff', 'title' => 'Order ' . $o['order_number'], 'order' => $o,
            'counterparty' => 'Buyer: ' . $b['legal_name'] . ' · Supplier: ' . $s['legal_name'],
            'items' => $svc->items($id), 'history' => $db->table('order_status_history')->where('order_id', $id)->orderBy('id', 'DESC')->get()->getResultArray(),
            'invoices' => $db->table('invoices')->where('reference_type', 'order')->where('reference_id', $id)->get()->getResultArray(),
            'inspections' => $db->table('inspection_requests')->where('order_id', $id)->get()->getResultArray(),
            'logistics' => $db->table('logistics_requests')->where('order_id', $id)->get()->getResultArray(),
            'disputes' => $db->table('disputes')->where('order_id', $id)->get()->getResultArray(),
            'statuses' => OrderService::STATUSES, 'actions' => $actions,
        ]);
    }

    public function status(int $id)
    {
        $o = model(OrderModel::class)->find($id) ?? $this->notFound();

        return $this->attempt(fn () => service('orders')->transition($o, (string) $this->request->getPost('to'), 'staff', $this->userId(), $this->request->getPost('note') ?: null), 'Order updated.');
    }
}
