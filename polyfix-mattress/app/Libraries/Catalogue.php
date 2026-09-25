<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * Read-only access to published website content: products, sizes, CMS pages
 * and FAQs. Only PUBLISHED rows are ever returned here — drafts stay in the
 * admin console until someone publishes them.
 */
final class Catalogue
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /** @return list<array<string,mixed>> */
    public function products(?string $category = null): array
    {
        $builder = $this->db->table('products p')
            ->select('p.id, p.slug, p.name, p.category, p.tagline, p.short_description, p.comfort_level, p.firmness_score,
                      p.warranty_years, p.is_featured, wp.headline, wp.gallery, MIN(v.mrp) AS price_from, COUNT(v.id) AS sizes')
            ->join('website_products wp', 'wp.product_id = p.id AND wp.is_published = 1', 'left')
            ->join('product_variants v', "v.product_id = p.id AND v.deleted_at IS NULL AND v.status = 'PUBLISHED'", 'left')
            ->where(['p.status' => 'PUBLISHED', 'p.deleted_at' => null])
            ->groupBy('p.id')->orderBy('p.sort_order')->orderBy('p.name');
        if ($category !== null) {
            $builder->where('p.category', $category);
        }

        return array_map([$this, 'withImage'], $builder->get()->getResultArray());
    }

    public function product(string $slug): ?array
    {
        $product = $this->db->table('products p')
            ->select('p.*, wp.headline, wp.subheadline, wp.body_html, wp.gallery, wp.highlights')
            ->join('website_products wp', 'wp.product_id = p.id AND wp.is_published = 1', 'left')
            ->where(['p.slug' => $slug, 'p.status' => 'PUBLISHED', 'p.deleted_at' => null])
            ->get()->getRowArray();
        if ($product === null) {
            return null;
        }

        foreach (['materials', 'features', 'highlights'] as $list) {
            $product[$list] = json_decode((string) ($product[$list] ?? '[]'), true) ?: [];
        }
        $product['specs']   = json_decode((string) ($product['specifications'] ?? '{}'), true) ?: [];
        $product['images']  = json_decode((string) ($product['gallery'] ?? '[]'), true) ?: [];
        $product['sizes']   = $this->db->table('product_variants')->select('id, sku, size_label, width_in, length_in, height_in, mrp, weight_kg')
            ->where(['product_id' => $product['id'], 'deleted_at' => null, 'status' => 'PUBLISHED'])
            ->orderBy('sort_order')->get()->getResultArray();
        $product['faqs'] = $this->faqs(productId: $product['id']);

        return $product;
    }

    /** @return list<string> */
    public function categories(): array
    {
        return array_column($this->db->table('products')->distinct()->select('category')
            ->where(['status' => 'PUBLISHED', 'deleted_at' => null])->orderBy('category')->get()->getResultArray(), 'category');
    }

    /** A published CMS page with its hero and sections decoded, or null. */
    public function page(string $slug): ?array
    {
        $page = $this->db->table('website_pages')->where(['slug' => $slug, 'status' => 'PUBLISHED'])->get()->getRowArray();
        if ($page === null) {
            return null;
        }
        $page['hero']     = json_decode((string) $page['hero'], true) ?: [];
        $page['sections'] = json_decode((string) $page['sections'], true) ?: [];

        return $page;
    }

    /** @return list<array{question:string, answer:string}> */
    public function faqs(?string $category = null, ?string $productId = null): array
    {
        $builder = $this->db->table('faqs')->select('question, answer')->where('is_published', 1)->orderBy('sort_order');
        if ($productId !== null) {
            $builder->where('product_id', $productId);
        } else {
            $builder->where('product_id', null);
            if ($category !== null) {
                $builder->where('category', $category);
            }
        }

        return $builder->get()->getResultArray();
    }

    private function withImage(array $row): array
    {
        $gallery      = json_decode((string) ($row['gallery'] ?? '[]'), true) ?: [];
        $row['image'] = $gallery[0] ?? null;

        return $row;
    }
}
