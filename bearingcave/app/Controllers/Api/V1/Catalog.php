<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApi;

class Catalog extends BaseApi
{
    public function categories()
    {
        return $this->ok(db_connect()->table('categories')->select('id, parent_id, name, slug')->where('is_active', 1)->orderBy('sort_order')->get()->getResultArray());
    }

    public function brands()
    {
        return $this->ok(db_connect()->table('brands')->select('id, name, slug, brand_type, country_code')->where('is_active', 1)->orderBy('name')->get()->getResultArray());
    }

    public function suggest()
    {
        return $this->ok(service('search')->suggest(mb_substr((string) $this->request->getGet('q'), 0, 60), $this->viewer()));
    }
}
