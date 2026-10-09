<?php

declare(strict_types=1);

namespace App\Controllers\Supplier;

use App\Controllers\BaseController;
use App\Exceptions\BusinessRuleException;
use App\Models\InventoryLotModel;
use App\Models\ProductImageModel;
use App\Models\ProductModel;
use App\Services\InventoryService;
use App\Services\ProductService;

/**
 * Supplier inventory management. Every product is loaded through owned()
 * so suppliers can never touch another company's listings.
 */
class Products extends BaseController
{
    public function index(): string
    {
        $cid = (int) $this->company()['id'];
        $m   = model(ProductModel::class)->select('products.*, categories.name AS category_name, brands.name AS brand_name')
            ->join('categories', 'categories.id = products.category_id')->join('brands', 'brands.id = products.brand_id', 'left')
            ->where('products.company_id', $cid);
        $q = trim((string) $this->request->getGet('q'));
        if ($q !== '') {
            $m->groupStart()->like('products.name', $q)->orLike('products.sku', $q)->orLike('products.part_number_norm', normalize_part($q))->groupEnd();
        }
        if ($s = $this->request->getGet('status')) {
            $m->where('products.status', $s);
        } else {
            $m->where('products.status !=', 'archived');
        }
        if (($age = $this->request->getGet('age')) && ($sql = InventoryService::ageBucketSql($age, 'products.inventory_age_date'))) {
            $m->where($sql, null, false);
        }
        $m->orderBy('products.updated_at', 'DESC');

        return view('supplier/products', [
            'title' => 'Inventory', 'area' => 'supplier', 'products' => $m->paginate(25), 'pager' => $m->pager,
            'limit' => service('entitlements')->limit($this->company(), 'max_active_products'), 'ages' => InventoryService::AGE_BUCKETS,
        ]);
    }

    public function export()
    {
        $rows = db_connect()->table('products p')->select('p.sku, p.part_number, p.oem_part_number, p.name, c.name AS category, b.name AS brand, p.item_condition, p.stock_on_hand, p.stock_reserved, p.moq, p.sale_mode, p.unit_price, p.lot_price, p.lot_quantity, p.currency, p.status, p.visibility, p.country_mode, p.inventory_age_date, p.warehouse_city, p.warehouse_country, p.view_count')
            ->join('categories c', 'c.id = p.category_id')->join('brands b', 'b.id = p.brand_id', 'left')
            ->where('p.company_id', $this->company()['id'])->where('p.deleted_at', null)->orderBy('p.sku')->get()->getResultArray();
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, array_merge(array_keys($rows[0] ?? ['sku' => 1]), ['age_bucket']));
        foreach ($rows as $r) {
            fputcsv($fh, array_merge(array_values($r), [InventoryService::AGE_BUCKETS[InventoryService::ageBucket($r['inventory_age_date']) ?? ''] ?? '']));
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        service('audit')->log('inventory.exported', ['company_id' => (int) $this->company()['id'], 'description' => count($rows) . ' rows']);

        return $this->response->download('inventory-report-' . date('Ymd') . '.csv', "\xEF\xBB\xBF" . $csv);
    }

    private function formData(?array $product): array
    {
        $c = $this->company();
        $db = db_connect();
        $id = $product['id'] ?? 0;

        return [
            'area' => 'supplier', 'company' => $c, 'product' => $product,
            'categories' => category_options(false), 'brands' => brand_options(), 'conditions' => ProductService::CONDITIONS, 'shipping' => ProductService::SHIPPING_OPTIONS,
            'can' => [
                'piece' => entitled('per_piece_pricing', $c), 'countries' => entitled('country_visibility_control', $c), 'direct' => entitled('direct_logistics', $c),
                'public' => entitled('public_profile', $c), 'advanced' => entitled('advanced_inventory', $c),
            ],
            'specs'     => $id ? $db->table('product_specifications')->where('product_id', $id)->orderBy('sort_order')->get()->getResultArray() : [],
            'fitments'  => $id ? $db->table('fitments')->where('product_id', $id)->get()->getResultArray() : [],
            'tiers'     => $id ? $db->table('pricing_tiers')->where('product_id', $id)->orderBy('min_qty')->get()->getResultArray() : [],
            'countries' => $id ? array_column($db->table('product_country_rules')->where('product_id', $id)->get()->getResultArray(), 'country_code') : [],
            'xrefs'     => $id ? implode("\n", array_map(static fn ($x) => $x['ref_brand'] . ':' . $x['ref_part_number'], $db->table('cross_references')->where('product_id', $id)->where('relation', 'declared_equivalent')->get()->getResultArray())) : '',
            'altNumbers' => $id ? implode("\n", array_map(static fn ($x) => ($x['brand_name'] ? $x['brand_name'] . ':' : '') . $x['part_number'], $db->table('part_numbers')->where('product_id', $id)->get()->getResultArray())) : '',
            'defaultConfidential' => (bool) ($db->table('supplier_profiles')->select('default_confidential')->where('company_id', $c['id'])->get()->getRow()->default_confidential ?? 1),
        ];
    }

    public function new(): string
    {
        return view('supplier/product_form', ['title' => 'Add inventory'] + $this->formData(null));
    }

    public function create()
    {
        $rules = service('products')->rules() + ['initial_quantity' => 'required|is_natural_no_zero', 'received_on' => 'required|valid_date[Y-m-d]', 'lot_code' => 'permit_empty|max_length[60]'];
        if ($r = $this->invalid($rules)) {
            return $r;
        }
        if ($this->request->getPost('received_on') > date('Y-m-d')) {
            return redirect()->back()->withInput()->with('error', 'The stock received date cannot be in the future.');
        }
        $product = null;
        $res = $this->attempt(function () use (&$product) {
            $db = db_connect();
            $db->transBegin();

            try {
                $product = service('products')->save($this->company(), $this->input());
                service('inventory')->receive((int) $product['id'], (int) $this->request->getPost('initial_quantity'), (string) $this->request->getPost('received_on'), $this->request->getPost('lot_code') ?: null, $this->request->getPost('warehouse_city') ?: null, 'receipt', 'Initial stock');
                $db->transCommit();
            } catch (\Throwable $e) {
                $db->transRollback();

                throw $e;
            }
        }, 'Product created.');

        return $product ? redirect()->to(site_url('supplier/products/' . $product['id'] . '/edit'))->with('success', 'Inventory saved as a draft. Add images/documents, then submit it for publishing.') : $res;
    }

    private function input(): array
    {
        $in = $this->request->getPost();
        $in['shipping_options'] = (array) ($in['shipping_options'] ?? []);
        $in['countries']        = (array) ($in['countries'] ?? []);
        $in['specs']            = (array) ($in['specs'] ?? []);
        $in['fitments']         = (array) ($in['fitments'] ?? []);
        $in['tiers']            = (array) ($in['tiers'] ?? []);
        $in['hide_supplier_identity'] = ($in['hide_supplier_identity'] ?? '0') === '1';

        return $in;
    }

    public function edit(int $id): string
    {
        $p = $this->owned(model(ProductModel::class), $id);
        $db = db_connect();

        return view('supplier/product_form', ['title' => 'Edit ' . $p['part_number'],
            'lots' => model(InventoryLotModel::class)->where('product_id', $id)->orderBy('received_on')->findAll(),
            'images' => $db->table('product_images')->where('product_id', $id)->orderBy('sort_order')->get()->getResultArray(),
            'documents' => service('documents')->forEntity('product', $id),
            'movements' => $db->table('inventory_movements')->where('product_id', $id)->orderBy('id', 'DESC')->limit(15)->get()->getResultArray(),
            'enquiries' => $db->table('product_enquiries')->where('product_id', $id)->countAllResults(),
        ] + $this->formData($p));
    }

    public function update(int $id)
    {
        $p = $this->owned(model(ProductModel::class), $id);
        if ($r = $this->invalid(service('products')->rules())) {
            return $r;
        }

        return $this->attempt(fn () => service('products')->save($this->company(), $this->input(), $p), $p['status'] === 'published' && service('products')->needsReview($this->company()) ? 'Changes saved. The listing returns to review before it is visible again.' : 'Changes saved.');
    }

    public function submit(int $id)
    {
        $p = $this->owned(model(ProductModel::class), $id);
        $status = null;
        $res = $this->attempt(function () use ($p, &$status) {
            $status = service('products')->submit($p, $this->company());
        }, 'Submitted.');

        return $status ? redirect()->back()->with('success', $status === 'published' ? 'Listing published.' : 'Listing submitted for BearingCave review.') : $res;
    }

    public function status(int $id)
    {
        $p  = $this->owned(model(ProductModel::class), $id);
        $to = (string) $this->request->getPost('status');
        if ($to === 'published' && $p['status'] === 'unpublished') {
            return $this->submit($id);
        }

        return $this->attempt(fn () => service('products')->setStatus($p, $to), 'Listing status updated.');
    }

    public function uploadImage(int $id)
    {
        $p    = $this->owned(model(ProductModel::class), $id);
        $file = $this->request->getFile('image');

        return $this->attempt(function () use ($p, $file) {
            if (! $file || ! $file->isValid()) {
                throw new BusinessRuleException('Choose an image to upload.');
            }
            if (! in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true) || $file->getSize() > 5 * 1024 * 1024) {
                throw new BusinessRuleException('Images must be JPG, PNG or WebP and at most 5 MB.');
            }
            $count = model(ProductImageModel::class)->where('product_id', $p['id'])->countAllResults();
            if ($count >= 8) {
                throw new BusinessRuleException('A listing can have up to 8 images.');
            }
            $dir = FCPATH . 'uploads/products/' . $p['id'];
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $name = bin2hex(random_bytes(12)) . '.jpg';
            // Re-encode: strips metadata (EXIF/GPS) and neutralises polyglot files.
            service('image')->withFile($file->getTempName())->resize(1600, 1600, true, 'auto')->convert(IMAGETYPE_JPEG)->save($dir . '/' . $name, 85);
            model(ProductImageModel::class)->insert(['product_id' => $p['id'], 'path' => $p['id'] . '/' . $name, 'alt_text' => mb_substr((string) ($this->request->getPost('alt_text') ?: $p['name']), 0, 191), 'sort_order' => $count, 'is_primary' => $count === 0 ? 1 : 0]);
        }, 'Image uploaded.');
    }

    public function deleteImage(int $id, int $imageId)
    {
        $p   = $this->owned(model(ProductModel::class), $id);
        $img = model(ProductImageModel::class)->where('product_id', $p['id'])->find($imageId);
        if (! $img) {
            $this->notFound();
        }
        $path = realpath(FCPATH . 'uploads/products/' . $img['path']);
        if ($path && str_starts_with($path, realpath(FCPATH . 'uploads/products'))) {
            @unlink($path);
        }
        model(ProductImageModel::class)->delete($imageId);
        if ($img['is_primary'] && ($next = model(ProductImageModel::class)->where('product_id', $p['id'])->orderBy('sort_order')->first())) {
            model(ProductImageModel::class)->update($next['id'], ['is_primary' => 1]);
        }

        return redirect()->back()->with('success', 'Image removed.');
    }

    public function uploadDocument(int $id)
    {
        $p = $this->owned(model(ProductModel::class), $id);
        if ($r = $this->invalid(['title' => 'required|max_length[191]', 'doc_type' => 'required|in_list[datasheet,test_certificate,certificate_of_conformity,packing_list,photos,other]', 'visibility' => 'required|in_list[private,platform,verified_buyers]'])) {
            return $r;
        }

        return $this->attempt(fn () => service('documents')->store($this->request->getFile('file'), [
            'company_id' => (int) $p['company_id'], 'entity_type' => 'product', 'entity_id' => (int) $p['id'], 'doc_type' => (string) $this->request->getPost('doc_type'),
            'title' => (string) $this->request->getPost('title'), 'visibility' => (string) $this->request->getPost('visibility'), 'review_status' => 'approved',
        ], ['pdf', 'jpg', 'jpeg', 'png', 'xlsx', 'csv']), 'Document uploaded.');
    }

    public function receive(int $id)
    {
        $p = $this->owned(model(ProductModel::class), $id);
        if ($r = $this->invalid(['quantity' => 'required|is_natural_no_zero', 'received_on' => 'required|valid_date[Y-m-d]', 'lot_code' => 'permit_empty|max_length[60]', 'warehouse_location' => 'permit_empty|max_length[191]'])) {
            return $r;
        }

        return $this->attempt(function () use ($p) {
            if ($this->request->getPost('received_on') > date('Y-m-d')) {
                throw new BusinessRuleException('The received date cannot be in the future.');
            }
            if (model(InventoryLotModel::class)->where('product_id', $p['id'])->where('lot_code', $this->request->getPost('lot_code'))->countAllResults() > 0) {
                throw new BusinessRuleException('That lot code already exists for this product.');
            }
            service('inventory')->receive((int) $p['id'], (int) $this->request->getPost('quantity'), (string) $this->request->getPost('received_on'), $this->request->getPost('lot_code') ?: null, $this->request->getPost('warehouse_location') ?: null, 'receipt', $this->request->getPost('note') ?: null);
        }, 'Stock received.');
    }

    public function adjust(int $id, int $lotId)
    {
        $p = $this->owned(model(ProductModel::class), $id);
        if ($r = $this->invalid(['delta' => 'required|integer', 'note' => 'required|max_length[255]'])) {
            return $r;
        }

        return $this->attempt(fn () => service('inventory')->adjust((int) $p['id'], $lotId, (int) $this->request->getPost('delta'), (string) $this->request->getPost('note')), 'Stock adjusted.');
    }
}
