<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApi;
use App\Models\CompanyModel;
use App\Models\ProductModel;

class Products extends BaseApi
{
    private const FILTERS = ['q', 'category', 'brand', 'condition', 'country', 'age', 'verified', 'min_price', 'max_price', 'min_qty', 'in_stock', 'id', 'od', 'w', 'make', 'model', 'year', 'spec', 'sort'];

    public function index()
    {
        $viewer = $this->viewer();
        $f = [];
        foreach (self::FILTERS as $k) {
            $v = $this->request->getGet($k);
            if (is_string($v) && $v !== '') {
                $f[$k] = mb_substr($v, 0, 100);
            }
        }
        $res = service('search')->search($f, $viewer, max(1, (int) $this->request->getGet('page')), min(100, max(1, (int) ($this->request->getGet('per_page') ?: 24))));

        return $this->ok(array_map(fn ($p) => $this->present($p, $viewer), $res['items']), ['total' => $res['total'], 'page' => $res['page'], 'per_page' => $res['perPage'], 'pages' => $res['pages']]);
    }

    public function show(string $uuid)
    {
        $viewer = $this->viewer();
        $p = model(ProductModel::class)->where('uuid', $uuid)->first();
        if (! $p || ! service('visibility')->canView($p, $viewer)) {
            return $this->error('Not found', 404);
        }
        $c = model(CompanyModel::class)->find($p['company_id']);
        $row = $p + ['legal_name' => $c['legal_name'], 'trade_name' => $c['trade_name'], 'public_alias' => $c['public_alias'], 'verification_status' => $c['verification_status'],
            'public_profile_enabled' => $c['public_profile_enabled'], 'show_contact_public' => $c['show_contact_public'], 'company_status' => $c['status'], 'company_type' => $c['company_type'], 'supplier_id' => $c['id'], 'match_type' => 'none'];
        $db = db_connect();
        $data = $this->present($row, $viewer);
        $data['specifications'] = $db->table('product_specifications')->select('spec_name AS name, spec_value AS value, unit')->where('product_id', $p['id'])->get()->getResultArray();
        $data['cross_references'] = array_map(static fn ($x) => ['brand' => $x['ref_brand'], 'part_number' => $x['ref_part_number'], 'verified_interchange' => $x['relation'] === 'verified_interchange'], $db->table('cross_references')->where('product_id', $p['id'])->get()->getResultArray());
        $data['fitments'] = $db->table('fitments')->select('make, model, variant, engine, year_from, year_to')->where('product_id', $p['id'])->get()->getResultArray();

        return $this->ok($data);
    }

    private function present(array $p, \App\Libraries\Viewer $v): array
    {
        $vis = service('visibility');
        $supplier = ['id' => $p['supplier_id'] ?? $p['company_id'], 'legal_name' => $p['legal_name'] ?? '', 'trade_name' => $p['trade_name'] ?? null, 'public_alias' => $p['public_alias'] ?? '',
            'verification_status' => $p['verification_status'] ?? '', 'public_profile_enabled' => $p['public_profile_enabled'] ?? 0, 'show_contact_public' => $p['show_contact_public'] ?? 0, 'status' => $p['company_status'] ?? 'active', 'company_type' => 'supplier'];
        $price = $vis->canSeePrice($p, $v) ? service('products')->priceFor($p, max(1, (int) $p['moq'])) : null;

        return [
            'id' => $p['uuid'], 'url' => site_url('product/' . $p['slug']), 'sku' => $p['sku'], 'name' => $p['name'], 'part_number' => $p['part_number'], 'oem_part_number' => $p['oem_part_number'],
            'brand' => $p['brand_name'] ?? null, 'category' => $p['category_name'] ?? null, 'condition' => $p['item_condition'],
            'available_quantity' => max(0, (int) $p['stock_on_hand'] - (int) $p['stock_reserved']), 'moq' => (int) $p['moq'], 'unit' => $p['unit_of_measure'],
            'sale_mode' => $p['sale_mode'], 'currency' => $p['currency'],
            'price' => $price && $price['total'] !== null ? ['unit_price' => $price['unit'], 'lot_price' => $p['lot_price'], 'lot_quantity' => $p['lot_quantity'], 'mode' => $price['mode']] : null,
            'price_on_request' => $p['price_visibility'] === 'on_request',
            'inventory_age' => \App\Services\InventoryService::ageBucket($p['inventory_age_date']),
            'warehouse_country' => $p['warehouse_country'],
            'supplier' => ['name' => $vis->supplierLabel($supplier, $p, $v), 'verified' => service('entitlements')->isVerifiedSupplier($supplier)],
            'match_type' => $p['match_type'] ?? null,
            'interchangeability_note' => ($p['match_type'] ?? '') === 'declared_cross_reference' ? 'Supplier-declared cross reference; interchangeability not verified.' : null,
        ];
    }
}
