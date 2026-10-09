<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\BrandModel;

class Brands extends AdminController
{
    public function index(): string
    {
        $rows = db_connect()->table('brands b')->select('b.*, (SELECT COUNT(*) FROM products x WHERE x.brand_id = b.id AND x.deleted_at IS NULL) AS products', false)->orderBy('b.name')->get()->getResultArray();

        return $this->page('brands', ['title' => 'Brands', 'rows' => $rows]);
    }

    public function save(?int $id = null)
    {
        if ($r = $this->invalid(['name' => 'required|max_length[120]', 'country_code' => 'permit_empty|exact_length[2]', 'brand_type' => 'required|in_list[oem,aftermarket,both]'])) {
            return $r;
        }
        $m    = model(BrandModel::class);
        $data = ['name' => trim((string) $this->request->getPost('name')), 'country_code' => $this->request->getPost('country_code') ?: null, 'brand_type' => $this->request->getPost('brand_type'), 'is_active' => $this->request->getPost('is_active') === '0' ? 0 : 1];
        if ($id) {
            $m->find($id) ?? $this->notFound();
            $m->update($id, $data);
        } else {
            $slug = make_slug($data['name']);
            if ($m->where('slug', $slug)->countAllResults() > 0) {
                return redirect()->back()->withInput()->with('error', 'A brand with this name already exists.');
            }
            $m->insert($data + ['slug' => $slug]);
        }
        service('audit')->log('brand.saved', ['entity_type' => 'brand', 'entity_id' => $id, 'description' => $data['name']]);

        return redirect()->back()->with('success', 'Brand saved.');
    }
}
