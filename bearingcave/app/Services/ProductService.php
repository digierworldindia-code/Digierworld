<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\CrossReferenceModel;
use App\Models\FitmentModel;
use App\Models\PartNumberModel;
use App\Models\PricingTierModel;
use App\Models\ProductCountryRuleModel;
use App\Models\ProductModel;
use App\Models\ProductSpecificationModel;

/**
 * Supplier/admin product management, with membership entitlements enforced
 * server-side (per-piece pricing, country control, publish limits).
 */
class ProductService
{
    public const CONDITIONS = [
        'new'           => 'New (genuine, unused)',
        'new_old_stock' => 'New old stock (NOS)',
        'new_open_box'  => 'New – open box / repacked',
        'refurbished'   => 'Refurbished',
        'used'          => 'Used',
    ];

    public const SHIPPING_OPTIONS = [
        'platform_managed' => 'BearingCave-managed shipping',
        'supplier_direct'  => 'Supplier-direct shipping',
        'confidential'     => 'Confidential (anonymous) shipping',
        'buyer_pickup'     => 'Buyer pickup / buyer-arranged',
    ];

    public function __construct(private ProductModel $products = new ProductModel())
    {
    }

    public function rules(): array
    {
        return [
            'name'              => 'required|max_length[191]',
            'sku'               => 'required|max_length[80]|regex_match[/^[A-Za-z0-9._\-\/]+$/]',
            'part_number'       => 'required|max_length[80]',
            'oem_part_number'   => 'permit_empty|max_length[80]',
            'category_id'       => 'required|is_natural_no_zero|is_not_unique[categories.id]',
            'brand_id'          => 'permit_empty|is_natural_no_zero|is_not_unique[brands.id]',
            'item_condition'    => 'required|in_list[' . implode(',', array_keys(self::CONDITIONS)) . ']',
            'sale_mode'         => 'required|in_list[lot,piece,both]',
            'unit_price'        => 'permit_empty|decimal|greater_than_equal_to[0]',
            'lot_price'         => 'permit_empty|decimal|greater_than_equal_to[0]',
            'lot_quantity'      => 'permit_empty|is_natural_no_zero',
            'moq'               => 'required|is_natural_no_zero',
            'currency'          => 'required|exact_length[3]|is_not_unique[currencies.code]',
            'price_visibility'  => 'required|in_list[show,on_request]',
            'visibility'        => 'required|in_list[public,verified_buyers,hidden]',
            'country_mode'      => 'required|in_list[all,allow_list,deny_list]',
            'warehouse_country' => 'permit_empty|exact_length[2]|is_not_unique[countries.iso2]',
            'warehouse_city'    => 'permit_empty|max_length[100]',
            'length_mm'         => 'permit_empty|decimal',
            'width_mm'          => 'permit_empty|decimal',
            'height_mm'         => 'permit_empty|decimal',
            'inner_diameter_mm' => 'permit_empty|decimal',
            'outer_diameter_mm' => 'permit_empty|decimal',
            'weight_kg'         => 'permit_empty|decimal',
            'description'       => 'permit_empty|max_length[10000]',
        ];
    }

    /**
     * Creates or updates a product for a supplier company.
     *
     * @param array $data validated input
     */
    public function save(array $company, array $data, ?array $existing = null, bool $byStaff = false): array
    {
        $ent = service('entitlements');

        if (in_array($data['sale_mode'], ['piece', 'both'], true) && ! $byStaff && ! $ent->has($company, 'per_piece_pricing')) {
            throw new BusinessRuleException('Per-piece pricing is available on the Verified Supplier plan. Please list this stock as a lot.');
        }
        if ($data['country_mode'] !== 'all' && ! $byStaff && ! $ent->has($company, 'country_visibility_control')) {
            throw new BusinessRuleException('Selecting allowed/excluded countries is a Verified Supplier feature.');
        }
        if ($data['sale_mode'] === 'lot' && (empty($data['lot_quantity']) || ($data['price_visibility'] === 'show' && $data['lot_price'] === ''))) {
            throw new BusinessRuleException('Lot listings need a lot quantity and (unless price is on request) a lot price.');
        }
        if ($data['sale_mode'] !== 'lot' && $data['price_visibility'] === 'show' && ($data['unit_price'] ?? '') === '') {
            throw new BusinessRuleException('Please enter a per-piece price or choose "Price on request".');
        }

        $shipping = array_values(array_intersect((array) ($data['shipping_options'] ?? ['platform_managed']), array_keys(self::SHIPPING_OPTIONS)));
        if ($shipping === []) {
            $shipping = ['platform_managed'];
        }
        if (in_array('supplier_direct', $shipping, true) && ! $byStaff && ! $ent->has($company, 'direct_logistics')) {
            throw new BusinessRuleException('Supplier-direct shipping is available on the Verified Supplier plan.');
        }

        $row = [
            'company_id'        => (int) $company['id'],
            'category_id'       => (int) $data['category_id'],
            'brand_id'          => $data['brand_id'] ?: null,
            'sku'               => trim($data['sku']),
            'name'              => trim($data['name']),
            'part_number'       => trim($data['part_number']),
            'part_number_norm'  => normalize_part($data['part_number']),
            'oem_part_number'   => trim((string) ($data['oem_part_number'] ?? '')) ?: null,
            'oem_part_number_norm' => normalize_part($data['oem_part_number'] ?? '') ?: null,
            'description'       => $data['description'] ?? null,
            'item_condition'    => $data['item_condition'],
            'unit_of_measure'   => $data['unit_of_measure'] ?? 'pcs',
            'currency'          => strtoupper($data['currency']),
            'sale_mode'         => $data['sale_mode'],
            'unit_price'        => ($data['unit_price'] ?? '') === '' ? null : $data['unit_price'],
            'lot_price'         => ($data['lot_price'] ?? '') === '' ? null : $data['lot_price'],
            'lot_quantity'      => ($data['lot_quantity'] ?? '') === '' ? null : (int) $data['lot_quantity'],
            'moq'               => (int) $data['moq'],
            'price_visibility'  => $data['price_visibility'],
            'visibility'        => $data['visibility'],
            'country_mode'      => $data['country_mode'],
            'hide_supplier_identity' => empty($data['hide_supplier_identity']) ? 0 : 1,
            'shipping_options'  => implode(',', $shipping),
            'warehouse_city'    => $data['warehouse_city'] ?? null,
            'warehouse_country' => ($data['warehouse_country'] ?? '') ?: $company['country_code'],
        ];
        foreach (['length_mm', 'width_mm', 'height_mm', 'inner_diameter_mm', 'outer_diameter_mm', 'weight_kg'] as $f) {
            $row[$f] = ($data[$f] ?? '') === '' ? null : $data[$f];
        }
        if (! $ent->has($company, 'public_profile') && ! $byStaff) {
            $row['hide_supplier_identity'] = 1; // unverified suppliers are always anonymous
        }
        $row['search_keywords'] = $this->keywords($row, $data);

        $dupe = $this->products->withDeleted()->where('company_id', $company['id'])->where('sku', $row['sku']);
        if ($existing) {
            $dupe->where('id !=', $existing['id']);
        }
        if ($dupe->countAllResults() > 0) {
            throw new BusinessRuleException("SKU {$row['sku']} is already used by another of your products.");
        }

        $db = db_connect();
        $db->transBegin();

        try {
            if ($existing) {
                if (in_array($existing['status'], ['published', 'rejected'], true) && $this->needsReview($company, $byStaff)) {
                    $row['status'] = 'pending_review';
                }
                $this->products->update($existing['id'], $row);
                $id = (int) $existing['id'];
            } else {
                $row['uuid']   = uuid4();
                $row['slug']   = $this->uniqueSlug($row['name'] . ' ' . $row['part_number']);
                $row['status'] = 'draft';
                $id            = (int) $this->products->insert($row);
            }

            $this->syncChildren($id, $data, $company, $byStaff);
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        service('audit')->log($existing ? 'product.updated' : 'product.created', [
            'entity_type' => 'product', 'entity_id' => $id, 'company_id' => (int) $company['id'],
            'description' => $row['name'] . ' (' . $row['part_number'] . ')',
        ]);

        return $this->products->find($id);
    }

    private function keywords(array $row, array $data): string
    {
        $parts = [$row['part_number_norm'], $row['oem_part_number_norm']];
        foreach ($this->parseRefs($data['cross_references'] ?? '') as $r) {
            $parts[] = normalize_part($r['part']);
        }

        return implode(' ', array_filter($parts));
    }

    /**
     * Parses "SKF:6204-2RS, FAG:6204.2RSR" (one per line or comma separated).
     *
     * @return list<array{brand:string,part:string}>
     */
    public function parseRefs(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\r\n,;]+/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$brand, $part] = str_contains($line, ':') ? array_map('trim', explode(':', $line, 2)) : ['', $line];
            if ($part !== '') {
                $out[] = ['brand' => $brand ?: 'Unspecified', 'part' => $part];
            }
        }

        return $out;
    }

    private function syncChildren(int $productId, array $data, array $company, bool $byStaff): void
    {
        if (array_key_exists('cross_references', $data)) {
            $m = model(CrossReferenceModel::class);
            $m->where('product_id', $productId)->where('relation', 'declared_equivalent')->delete();
            $seen = [];
            foreach ($this->parseRefs((string) $data['cross_references']) as $r) {
                $key = strtolower($r['brand']) . '|' . normalize_part($r['part']);
                if (isset($seen[$key]) || $m->where(['product_id' => $productId, 'ref_brand' => $r['brand'], 'ref_part_number_norm' => normalize_part($r['part'])])->countAllResults() > 0) {
                    continue;
                }
                $seen[$key] = true;
                $m->insert(['product_id' => $productId, 'ref_brand' => $r['brand'], 'ref_part_number' => $r['part'], 'ref_part_number_norm' => normalize_part($r['part']), 'relation' => 'declared_equivalent']);
            }
        }

        if (array_key_exists('alt_part_numbers', $data)) {
            $m = model(PartNumberModel::class);
            $m->where('product_id', $productId)->delete();
            $seen = [];
            foreach ($this->parseRefs((string) $data['alt_part_numbers']) as $r) {
                $norm = normalize_part($r['part']);
                if (isset($seen[$norm])) {
                    continue;
                }
                $seen[$norm] = true;
                $m->insert(['product_id' => $productId, 'number_type' => 'alternate', 'part_number' => $r['part'], 'part_number_norm' => $norm, 'brand_name' => $r['brand'] === 'Unspecified' ? null : $r['brand']]);
            }
        }

        if (isset($data['specs']) && is_array($data['specs'])) {
            $m = model(ProductSpecificationModel::class);
            $m->where('product_id', $productId)->delete();
            $i = 0;
            foreach ($data['specs'] as $s) {
                if (trim($s['name'] ?? '') === '' || trim($s['value'] ?? '') === '') {
                    continue;
                }
                $m->insert(['product_id' => $productId, 'spec_name' => mb_substr(trim($s['name']), 0, 100), 'spec_value' => mb_substr(trim($s['value']), 0, 191), 'unit' => mb_substr(trim($s['unit'] ?? ''), 0, 20) ?: null, 'sort_order' => $i++]);
            }
        }

        if (isset($data['fitments']) && is_array($data['fitments'])) {
            $m = model(FitmentModel::class);
            $m->where('product_id', $productId)->delete();
            foreach ($data['fitments'] as $f) {
                if (trim($f['make'] ?? '') === '') {
                    continue;
                }
                $m->insert([
                    'product_id' => $productId, 'make' => trim($f['make']), 'model' => trim($f['model'] ?? '') ?: null,
                    'variant' => trim($f['variant'] ?? '') ?: null, 'engine' => trim($f['engine'] ?? '') ?: null,
                    'year_from' => ($f['year_from'] ?? '') !== '' ? (int) $f['year_from'] : null,
                    'year_to' => ($f['year_to'] ?? '') !== '' ? (int) $f['year_to'] : null,
                ]);
            }
        }

        if (isset($data['tiers']) && is_array($data['tiers'])) {
            $m = model(PricingTierModel::class);
            $m->where('product_id', $productId)->delete();
            if ($byStaff || service('entitlements')->has($company, 'per_piece_pricing')) {
                $seen = [];
                foreach ($data['tiers'] as $t) {
                    $min = (int) ($t['min_qty'] ?? 0);
                    if ($min < 1 || ($t['unit_price'] ?? '') === '' || ! is_numeric($t['unit_price']) || isset($seen[$min])) {
                        continue;
                    }
                    $seen[$min] = true;
                    $m->insert(['product_id' => $productId, 'min_qty' => $min, 'max_qty' => ($t['max_qty'] ?? '') !== '' ? (int) $t['max_qty'] : null, 'unit_price' => $t['unit_price']]);
                }
            }
        }

        if (array_key_exists('countries', $data)) {
            $m = model(ProductCountryRuleModel::class);
            $m->where('product_id', $productId)->delete();
            $mode = $data['country_mode'];
            if ($mode !== 'all') {
                $rule = $mode === 'allow_list' ? 'allow' : 'deny';
                foreach (array_unique((array) $data['countries']) as $cc) {
                    if (preg_match('/^[A-Z]{2}$/', (string) $cc)) {
                        $m->insert(['product_id' => $productId, 'country_code' => $cc, 'rule' => $rule]);
                    }
                }
                if ($mode === 'allow_list' && $m->where('product_id', $productId)->countAllResults() === 0) {
                    throw new BusinessRuleException('Choose at least one allowed country, or make the listing visible in all countries.');
                }
            }
        }
    }

    public function needsReview(array $company, bool $byStaff = false): bool
    {
        if ($byStaff) {
            return false;
        }
        $policy = policy('catalog.review_policy', 'all');

        return $policy === 'all' || ($policy === 'unverified' && ! service('entitlements')->isVerifiedSupplier($company));
    }

    /**
     * Supplier submits a product: goes to review or straight to published.
     */
    public function submit(array $product, array $company, bool $byStaff = false, bool $notify = true): string
    {
        if ((int) $product['stock_on_hand'] <= 0) {
            throw new BusinessRuleException('Add stock (at least one inventory lot) before publishing.');
        }
        $this->assertPublishLimit($company, (int) $product['id']);
        $status = $this->needsReview($company, $byStaff) ? 'pending_review' : 'published';
        $this->products->update($product['id'], ['status' => $status, 'published_at' => $status === 'published' ? date('Y-m-d H:i:s') : null]);
        if ($status === 'pending_review' && $notify) {
            service('notifications')->notifyStaff(['procurement_manager'], 'product.review', 'Product awaiting review', $product['name'] . ' (' . $product['part_number'] . ')', 'admin/products/' . $product['id']);
        }
        service('audit')->log('product.submitted', ['entity_type' => 'product', 'entity_id' => (int) $product['id'], 'company_id' => (int) $company['id'], 'description' => "Status → {$status}"]);

        return $status;
    }

    public function assertPublishLimit(array $company, int $excludeProductId = 0): void
    {
        $limit = service('entitlements')->limit($company, 'max_active_products');
        if ($limit === null) {
            return;
        }
        $active = $this->products->where('company_id', $company['id'])->whereIn('status', ['published', 'pending_review'])->where('id !=', $excludeProductId)->countAllResults();
        if ($active >= $limit) {
            throw new BusinessRuleException("Your plan allows {$limit} active listings. Archive a listing or upgrade to publish more.");
        }
    }

    public function review(array $product, string $decision, ?string $notes, int $reviewerId): void
    {
        if ($product['status'] !== 'pending_review') {
            throw new BusinessRuleException('Only products pending review can be approved or rejected.');
        }
        $approve = $decision === 'approve';
        $this->products->update($product['id'], [
            'status'       => $approve ? 'published' : 'rejected',
            'review_notes' => $notes,
            'approved_by'  => $approve ? $reviewerId : null,
            'approved_at'  => $approve ? date('Y-m-d H:i:s') : null,
            'published_at' => $approve ? date('Y-m-d H:i:s') : null,
        ]);
        service('notifications')->notifyCompany((int) $product['company_id'], 'product.reviewed', $approve ? 'Listing approved' : 'Listing needs changes', $product['name'] . ($notes ? ' — ' . $notes : ''), 'supplier/products/' . $product['id'] . '/edit');
        service('audit')->log($approve ? 'product.approved' : 'product.rejected', ['entity_type' => 'product', 'entity_id' => (int) $product['id'], 'company_id' => (int) $product['company_id'], 'description' => (string) $notes]);
    }

    public function setStatus(array $product, string $status): void
    {
        $allowed = [
            'published'   => ['unpublished', 'archived'],
            'unpublished' => ['archived'],
            'draft'       => ['archived'],
            'rejected'    => ['archived'],
            'pending_review' => ['draft'],
            'archived'    => ['draft'],
        ];
        if (! in_array($status, $allowed[$product['status']] ?? [], true)) {
            throw new BusinessRuleException("A {$product['status']} listing cannot be changed to {$status}.");
        }
        if ($status === 'archived' && (int) $product['stock_reserved'] > 0) {
            throw new BusinessRuleException('This listing has stock reserved for open orders and cannot be archived yet.');
        }
        $this->products->update($product['id'], ['status' => $status]);
        service('audit')->log('product.status', ['entity_type' => 'product', 'entity_id' => (int) $product['id'], 'company_id' => (int) $product['company_id'], 'description' => "{$product['status']} → {$status}"]);
    }

    private function uniqueSlug(string $text): string
    {
        $base = make_slug($text, 200);
        $slug = $base;
        $i    = 2;
        while ($this->products->withDeleted()->where('slug', $slug)->countAllResults() > 0) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    /**
     * Price for a quantity (tier → unit price → lot price per piece).
     *
     * @return array{unit:float|null,total:float|null,mode:string}
     */
    public function priceFor(array $product, int $qty): array
    {
        if (in_array($product['sale_mode'], ['piece', 'both'], true) && $product['unit_price'] !== null) {
            $tier = model(PricingTierModel::class)->where('product_id', $product['id'])->where('min_qty <=', $qty)
                ->groupStart()->where('max_qty', null)->orWhere('max_qty >=', $qty)->groupEnd()
                ->orderBy('min_qty', 'DESC')->first();
            $unit = (float) ($tier['unit_price'] ?? $product['unit_price']);

            return ['unit' => $unit, 'total' => round($unit * $qty, 2), 'mode' => 'piece'];
        }
        if ($product['lot_price'] !== null && (int) $product['lot_quantity'] > 0) {
            $lots = $qty / (int) $product['lot_quantity'];

            return ['unit' => round((float) $product['lot_price'] / (int) $product['lot_quantity'], 4), 'total' => round($lots * (float) $product['lot_price'], 2), 'mode' => 'lot'];
        }

        return ['unit' => null, 'total' => null, 'mode' => 'on_request'];
    }

    /**
     * Validates a requested purchase quantity against MOQ, lot size and sale mode.
     */
    public function assertOrderableQuantity(array $product, int $qty): void
    {
        if ($qty < (int) $product['moq']) {
            throw new BusinessRuleException("Minimum order quantity for {$product['part_number']} is {$product['moq']}.");
        }
        if ($product['sale_mode'] === 'lot' && (int) $product['lot_quantity'] > 0 && $qty % (int) $product['lot_quantity'] !== 0) {
            throw new BusinessRuleException("{$product['part_number']} is sold in lots of {$product['lot_quantity']}; please order a multiple of the lot size.");
        }
    }
}
