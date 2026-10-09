<?php

declare(strict_types=1);

namespace App\Controllers\Buyer;

use App\Controllers\BaseController;

class Enquiries extends BaseController
{
    public function index(): string
    {
        $rows = db_connect()->table('product_enquiries e')->select('e.*, p.name, p.part_number, p.slug')
            ->join('products p', 'p.id = e.product_id')->where('e.buyer_company_id', service('companyContext')->companyId())
            ->orderBy('e.id', 'DESC')->limit(100)->get()->getResultArray();

        return view('buyer/enquiries', ['title' => 'My enquiries', 'area' => 'buyer', 'rows' => $rows]);
    }
}
