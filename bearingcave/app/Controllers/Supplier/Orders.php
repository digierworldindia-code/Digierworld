<?php

declare(strict_types=1);

namespace App\Controllers\Supplier;

use App\Controllers\BaseController;
use App\Models\CompanyModel;
use App\Models\OrderModel;
use App\Services\OrderService;

class Orders extends BaseController
{
    public function index(): string
    {
        $m = model(OrderModel::class)->where('supplier_company_id', $this->company()['id']);
        if ($s = $this->request->getGet('status')) {
            $m->where('status', $s);
        }
        $m->orderBy('id', 'DESC');

        return view('buyer/orders', ['title' => 'Orders', 'area' => 'supplier', 'orders' => $m->paginate(20), 'pager' => $m->pager, 'statuses' => OrderService::STATUSES]);
    }

    public function show(int $id): string
    {
        $order = $this->owned(model(OrderModel::class), $id, 'supplier_company_id');
        $buyer = model(CompanyModel::class)->find($order['buyer_company_id']);
        $db    = db_connect();
        $svc   = service('orders');
        $actions = [];
        foreach (['confirmed' => 'Confirm order (stock & terms)', 'cancelled' => 'Decline / cancel order', 'in_fulfilment' => 'Start fulfilment', 'shipped' => 'Mark as dispatched'] as $to => $label) {
            if ($svc->canTransition($order, $to, 'supplier')) {
                $actions[$to] = $label;
            }
        }

        return view('shared/order', [
            'title' => 'Order ' . $order['order_number'], 'area' => 'supplier', 'order' => $order, 'side' => 'supplier',
            // Buyer identity is relayed through BearingCave; suppliers see the alias.
            'counterparty' => 'Buyer ' . $buyer['public_alias'] . ' · ' . country_name($buyer['country_code']), 'counterpartyVerified' => service('entitlements')->isVerifiedBuyer($buyer),
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
        $order = $this->owned(model(OrderModel::class), $id, 'supplier_company_id');

        return $this->attempt(fn () => service('orders')->transition($order, (string) $this->request->getPost('to'), 'supplier', $this->userId(), $this->request->getPost('note') ?: null), 'Order updated.');
    }
}
