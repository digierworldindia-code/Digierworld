<?php

declare(strict_types=1);

namespace App\Controllers\Buyer;

use App\Controllers\BaseController;

class Saved extends BaseController
{
    public function index(): string
    {
        $viewer = service('visibility')->current();
        $b = db_connect()->table('saved_products s')
            ->select('p.*, b.name AS brand_name, co.public_alias, co.legal_name, co.trade_name, co.verification_status, co.public_profile_enabled, co.show_contact_public, co.status AS company_status, co.company_type, co.id AS supplier_id,
                (SELECT path FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.is_primary DESC, pi.sort_order LIMIT 1) AS image_path')
            ->join('products p', 'p.id = s.product_id')->join('brands b', 'b.id = p.brand_id', 'left')->join('companies co', 'co.id = p.company_id')
            ->where('s.user_id', $this->userId());
        service('visibility')->apply($b, $viewer);

        return view('buyer/saved', ['title' => 'Saved inventory', 'area' => 'buyer', 'items' => $b->orderBy('s.id', 'DESC')->get()->getResultArray(), 'viewer' => $viewer]);
    }
}
