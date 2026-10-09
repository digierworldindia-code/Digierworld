<?php

declare(strict_types=1);

namespace App\Controllers\Buyer;

use App\Controllers\BaseController;
use App\Models\CompanyModel;
use App\Models\OrderModel;
use App\Services\OrderService;

class Orders extends BaseController
{
    public function index(): string
    {
        $m = model(OrderModel::class)->where('buyer_company_id', service('companyContext')->companyId());
        if ($s = $this->request->getGet('status')) {
            $m->where('status', $s);
        }
        $m->orderBy('id', 'DESC');

        return view('buyer/orders', ['title' => 'Orders', 'area' => 'buyer', 'orders' => $m->paginate(20), 'pager' => $m->pager, 'statuses' => OrderService::STATUSES]);
    }

    public function show(int $id): string
    {
        $order = $this->owned(model(OrderModel::class), $id, 'buyer_company_id');
        $supplier = model(CompanyModel::class)->find($order['supplier_company_id']);
        $db = db_connect();

        return view('shared/order', [
            'title' => 'Order ' . $order['order_number'], 'area' => 'buyer', 'order' => $order, 'side' => 'buyer',
            'counterparty' => $order['logistics_mode'] === 'confidential' ? 'Supplier ' . $supplier['public_alias'] . ' (confidential)' : service('visibility')->supplierLabel($supplier),
            'counterpartyVerified' => service('entitlements')->isVerifiedSupplier($supplier),
            'items' => service('orders')->items($id), 'history' => $db->table('order_status_history')->where('order_id', $id)->orderBy('id', 'DESC')->get()->getResultArray(),
            'invoices' => $db->table('invoices')->where('reference_type', 'order')->where('reference_id', $id)->get()->getResultArray(),
            'inspections' => $db->table('inspection_requests')->where('order_id', $id)->get()->getResultArray(),
            'logistics' => $db->table('logistics_requests')->where('order_id', $id)->get()->getResultArray(),
            'disputes' => $db->table('disputes')->where('order_id', $id)->get()->getResultArray(),
            'statuses' => OrderService::STATUSES, 'actions' => $this->actions($order),
        ]);
    }

    private function actions(array $order): array
    {
        $svc = service('orders');
        $out = [];
        foreach (['cancelled' => 'Cancel order', 'delivered' => 'Confirm delivery received', 'completed' => 'Mark order complete'] as $to => $label) {
            if ($svc->canTransition($order, $to, 'buyer')) {
                $out[$to] = $label;
            }
        }

        return $out;
    }

    public function status(int $id)
    {
        $order = $this->owned(model(OrderModel::class), $id, 'buyer_company_id');
        $to    = (string) $this->request->getPost('to');

        return $this->attempt(fn () => service('orders')->transition($order, $to, 'buyer', $this->userId(), $this->request->getPost('note') ?: null), 'Order updated.');
    }
}
