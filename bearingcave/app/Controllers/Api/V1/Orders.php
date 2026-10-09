<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApi;

class Orders extends BaseApi
{
    public function index()
    {
        $c = service('companyContext')->company((int) auth('tokens')->id());
        if (! $c) {
            return $this->error('No company linked to this token', 403);
        }
        $col  = $c['company_type'] === 'buyer' ? 'buyer_company_id' : 'supplier_company_id';
        $rows = db_connect()->table('orders')->select('id, order_number, status, payment_status, shipment_status, currency, total, created_at')->where($col, $c['id'])->orderBy('id', 'DESC')->limit(100)->get()->getResultArray();

        return $this->ok($rows);
    }
}
