<?php

namespace App\Controllers\Admin;

use App\Exceptions\AppException;
use App\Libraries\Audit;
use App\Libraries\Tx;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * The product catalogue: ranges and their sizes. What is stored here decides
 * what the public pages claim and what warranty term a sale gets, so changes
 * are versioned and audited.
 */
class Products extends AdminController
{
    private const RULES = [
        'name'              => 'required|max_length[160]|safe_text',
        'slug'              => 'required|max_length[160]|regex_match[/^[a-z0-9]+(?:-[a-z0-9]+)*$/]',
        'sku_prefix'        => 'required|max_length[20]|regex_match[/^[A-Z0-9-]+$/]',
        'category'          => 'required|max_length[60]|safe_text',
        'tagline'           => 'permit_empty|max_length[200]|safe_text',
        'short_description' => 'required|max_length[400]|safe_text',
        'description'       => 'required|max_length[8000]|safe_text',
        'comfort_level'     => 'required|max_length[40]|safe_text',
        'firmness_score'    => 'required|is_natural_no_zero|less_than_equal_to[10]',
        'warranty_years'    => 'required|is_natural_no_zero|less_than_equal_to[25]',
        'trial_nights'      => 'permit_empty|is_natural|less_than_equal_to[365]',
        'care_instructions' => 'permit_empty|max_length[2000]|safe_text',
        'sort_order'        => 'permit_empty|is_natural',
    ];

    public function index(): string
    {
        $builder = db_connect()->table('products p')
            ->select('p.id, p.name, p.slug, p.category, p.status, p.is_featured, p.warranty_years, p.sort_order,
                      (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.deleted_at IS NULL) sizes,
                      (SELECT MIN(v.mrp) FROM product_variants v WHERE v.product_id = p.id AND v.deleted_at IS NULL) price_from,
                      (SELECT COUNT(*) FROM mattresses m JOIN product_variants v ON v.id = m.product_variant_id
                        WHERE v.product_id = p.id AND m.deleted_at IS NULL) units', false)
            ->where('p.deleted_at', null)->orderBy('p.sort_order')->orderBy('p.name');

        return $this->render('admin/products/index', 'Products', 'admin/products', ['list' => $this->paginate($builder, 50)]);
    }

    public function new(): string
    {
        return $this->render('admin/products/form', 'New product', 'admin/products', ['p' => null, 'variants' => []]);
    }

    public function create(): RedirectResponse
    {
        $in = $this->validated(self::RULES);
        if ($in instanceof RedirectResponse) {
            return $in;
        }

        return $this->act(function () use ($in) {
            $id = uuid4();
            Tx::run(function (BaseConnection $db) use ($id, $in): void {
                if ($db->table('products')->where('slug', $in['slug'])->countAllResults() > 0) {
                    throw AppException::conflict('Another product already uses that web address.');
                }
                $db->table('products')->insert($this->columns($in) + ['id' => $id, 'status' => 'DRAFT', 'created_by' => $this->ctx->userId()]);
                Audit::instance($db)->record('PRODUCT_CREATED', 'product', $id, null, ['name' => $in['name'], 'slug' => $in['slug']]);
            }, db_connect());

            return $id;
        }, 'Product created as a draft. Add its sizes, then publish.', site_url('admin/products'));
    }

    public function show(string $id): string
    {
        $db = db_connect();
        $p  = $db->table('products')->where(['id' => $id, 'deleted_at' => null])->get()->getRowArray() ?? $this->notFound();

        return $this->render('admin/products/form', $p['name'], 'admin/products', [
            'p'        => $p,
            'variants' => $db->table('product_variants')->where(['product_id' => $id, 'deleted_at' => null])->orderBy('sort_order')->get()->getResultArray(),
            'units'    => (int) $db->query(
                'SELECT COUNT(*) n FROM mattresses m JOIN product_variants v ON v.id = m.product_variant_id WHERE v.product_id = ? AND m.deleted_at IS NULL',
                [$id],
            )->getRow()->n,
        ]);
    }

    public function update(string $id): RedirectResponse
    {
        $in = $this->validated(self::RULES);
        if ($in instanceof RedirectResponse) {
            return $in;
        }
        $materials = $this->post('materials', 4000);
        $features  = $this->post('features', 4000);
        $specs     = (string) $this->request->getPost('specifications');

        return $this->act(function () use ($id, $in, $materials, $features, $specs): void {
            $decoded = $specs === '' ? [] : json_decode($specs, true);
            if (! is_array($decoded)) {
                throw AppException::rule('Specifications must be valid JSON: ' . json_last_error_msg() . '.');
            }

            Tx::run(function (BaseConnection $db) use ($id, $in, $materials, $features, $decoded): void {
                $before = $db->table('products')->where(['id' => $id, 'deleted_at' => null])->get()->getRowArray() ?? throw AppException::notFound('product');
                if ($in['slug'] !== $before['slug'] && $db->table('products')->where('slug', $in['slug'])->where('id !=', $id)->countAllResults() > 0) {
                    throw AppException::conflict('Another product already uses that web address.');
                }
                $lines = static fn (string $text): array => array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $text))));

                $audit = Audit::instance($db);
                $audit->version('product', $id, $before, 'product details updated');
                $db->table('products')->where('id', $id)->update($this->columns($in) + [
                    'materials'      => json_encode($lines($materials), JSON_UNESCAPED_UNICODE),
                    'features'       => json_encode($lines($features), JSON_UNESCAPED_UNICODE),
                    'specifications' => json_encode($decoded, JSON_UNESCAPED_UNICODE),
                    'updated_by'     => $this->ctx->userId(),
                ]);
                $audit->record('PRODUCT_UPDATED', 'product', $id, ['name' => $before['name'], 'warrantyYears' => (int) $before['warranty_years']],
                    ['name' => $in['name'], 'warrantyYears' => (int) $in['warranty_years']]);
            }, db_connect());
        }, 'Product saved.', site_url('admin/products/' . $id));
    }

    public function status(string $id): RedirectResponse
    {
        $status   = (string) $this->request->getPost('status');
        $featured = $this->request->getPost('is_featured') === '1';

        return $this->act(function () use ($id, $status, $featured): void {
            if (! in_array($status, ['DRAFT', 'PUBLISHED', 'ARCHIVED'], true)) {
                throw AppException::rule('Choose a valid status.');
            }
            Tx::run(function (BaseConnection $db) use ($id, $status, $featured): void {
                $before = $db->table('products')->where(['id' => $id, 'deleted_at' => null])->get()->getRowArray() ?? throw AppException::notFound('product');
                if ($status === 'PUBLISHED' && $db->table('product_variants')->where(['product_id' => $id, 'deleted_at' => null, 'status' => 'PUBLISHED'])->countAllResults() === 0) {
                    throw AppException::rule('Publish at least one size first — a product page with no sizes has nothing to sell.');
                }
                $db->table('products')->where('id', $id)->update([
                    'status'       => $status,
                    'is_featured'  => $featured ? 1 : 0,
                    'published_at' => $status === 'PUBLISHED' ? ($before['published_at'] ?? utc_now()) : $before['published_at'],
                    'updated_by'   => $this->ctx->userId(),
                ]);
                Audit::instance($db)->record('PRODUCT_STATUS_CHANGED', 'product', $id, ['status' => $before['status']], ['status' => $status, 'featured' => $featured]);
            }, db_connect());
        }, 'Product updated.', site_url('admin/products/' . $id));
    }

    /** Adds or updates one size. Prices are recommended retail; the dealer sets the final price. */
    public function saveVariant(string $id): RedirectResponse
    {
        $variantId = (string) $this->request->getPost('variant_id');
        $in        = $this->validated([
            'sku'        => 'required|max_length[40]|regex_match[/^[A-Z0-9-]+$/]',
            'size_label' => 'required|max_length[60]|safe_text',
            'width_in'   => 'required|decimal',
            'length_in'  => 'required|decimal',
            'height_in'  => 'required|decimal',
            'mrp'        => 'required|decimal',
            'weight_kg'  => 'permit_empty|decimal',
            'sort_order' => 'permit_empty|is_natural',
            'status'     => 'required|in_list[DRAFT,PUBLISHED,ARCHIVED]',
        ]);
        if ($in instanceof RedirectResponse) {
            return $in;
        }

        return $this->act(function () use ($id, $variantId, $in): void {
            $db  = db_connect();
            $row = [
                'product_id' => $id,
                'sku'        => strtoupper($in['sku']),
                'size_label' => $in['size_label'],
                'width_in'   => $in['width_in'],
                'length_in'  => $in['length_in'],
                'height_in'  => $in['height_in'],
                'mrp'        => $in['mrp'],
                'weight_kg'  => ($in['weight_kg'] ?? '') === '' ? null : $in['weight_kg'],
                'sort_order' => (int) ($in['sort_order'] ?? 0),
                'status'     => $in['status'],
            ];
            if (is_uuid($variantId)) {
                $db->table('product_variants')->where(['id' => $variantId, 'product_id' => $id])->update($row);
            } else {
                $variantId = uuid4();
                $db->table('product_variants')->insert($row + ['id' => $variantId]);
            }
            Audit::instance()->record('PRODUCT_VARIANT_SAVED', 'product_variant', $variantId, null, ['sku' => $row['sku'], 'mrp' => $row['mrp'], 'status' => $row['status']]);
        }, 'Size saved.', site_url('admin/products/' . $id));
    }

    /** Soft delete. A product with units in the field keeps its record. */
    public function delete(string $id): RedirectResponse
    {
        $reason = $this->post('reason', 500);

        return $this->act(function () use ($id, $reason): void {
            if (mb_strlen($reason) < 5) {
                throw AppException::rule('Give a reason of at least 5 characters.');
            }
            Tx::run(function (BaseConnection $db) use ($id, $reason): void {
                $product = $db->table('products')->where(['id' => $id, 'deleted_at' => null])->get()->getRowArray() ?? throw AppException::notFound('product');
                $units = (int) $db->query('SELECT COUNT(*) n FROM mattresses m JOIN product_variants v ON v.id = m.product_variant_id WHERE v.product_id = ? AND m.deleted_at IS NULL', [$id])->getRow()->n;
                if ($units > 0) {
                    throw AppException::rule("{$units} mattress(es) of this product exist, so the record must stay. Archive it instead — it leaves the website but keeps its history.");
                }
                $db->table('products')->where('id', $id)->update([
                    'deleted_at' => utc_now(), 'deleted_by' => $this->ctx->userId(), 'delete_reason' => $reason,
                ]);
                Audit::instance($db)->record('PRODUCT_DELETED', 'product', $id, ['name' => $product['name']], ['deleted' => true], $reason);
            }, db_connect());
        }, 'Product removed.', site_url('admin/products'));
    }

    private function columns(array $in): array
    {
        return [
            'name'              => $in['name'],
            'slug'              => $in['slug'],
            'sku_prefix'        => strtoupper($in['sku_prefix']),
            'category'          => $in['category'],
            'tagline'           => ($in['tagline'] ?? '') === '' ? null : $in['tagline'],
            'short_description' => $in['short_description'],
            'description'       => $in['description'],
            'comfort_level'     => $in['comfort_level'],
            'firmness_score'    => (int) $in['firmness_score'],
            'warranty_years'    => (int) $in['warranty_years'],
            'trial_nights'      => (int) ($in['trial_nights'] ?? 0),
            'care_instructions' => ($in['care_instructions'] ?? '') === '' ? null : $in['care_instructions'],
            'sort_order'        => (int) ($in['sort_order'] ?? 0),
        ];
    }
}
