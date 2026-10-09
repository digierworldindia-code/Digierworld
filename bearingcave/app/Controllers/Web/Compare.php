<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\CompanyModel;
use App\Models\ProductModel;

/**
 * Side-by-side comparison (session based). Prices and identities follow the
 * same visibility rules as everywhere else.
 */
class Compare extends BaseController
{
    private function ids(): array
    {
        return array_values(array_unique(array_map('intval', (array) session('compare'))));
    }

    public function index(): string
    {
        $vis    = service('visibility');
        $viewer = $vis->current();
        $rows   = [];
        foreach ($this->ids() as $id) {
            $p = model(ProductModel::class)->find($id);
            if (! $p || ! $vis->canView($p, $viewer)) {
                continue;
            }
            $s      = model(CompanyModel::class)->find($p['company_id']);
            $rows[] = [
                'product' => $p, 'supplier' => $s, 'label' => $vis->supplierLabel($s, $p, $viewer), 'verified' => service('entitlements')->isVerifiedSupplier($s),
                'price' => $vis->canSeePrice($p, $viewer), 'available' => service('inventory')->available($p),
                'brand' => $p['brand_id'] ? db_connect()->table('brands')->where('id', $p['brand_id'])->get()->getRowArray() : null,
            ];
        }
        $advanced = $viewer->company && service('entitlements')->has($viewer->company, 'advanced_comparison');

        return view('web/compare', ['title' => 'Compare inventory', 'robots' => 'noindex,nofollow', 'rows' => $rows, 'advanced' => $advanced]);
    }

    public function add(int $id)
    {
        $p = model(ProductModel::class)->find($id);
        if (! $p || ! service('visibility')->canView($p)) {
            $this->notFound();
        }
        $ids = $this->ids();
        $max = (int) policy('catalog.max_compare', 4);
        if (! in_array($id, $ids, true)) {
            if (count($ids) >= $max) {
                return redirect()->back()->with('error', "You can compare up to {$max} listings. Remove one first.");
            }
            $ids[] = $id;
        }
        session()->set('compare', $ids);

        return redirect()->back()->with('success', 'Added to comparison (' . count($ids) . '). ');
    }

    public function remove(int $id)
    {
        session()->set('compare', array_values(array_diff($this->ids(), [$id])));

        return redirect()->back();
    }

    public function clear()
    {
        session()->remove('compare');

        return redirect()->to(site_url('compare'));
    }
}
