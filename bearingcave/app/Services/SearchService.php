<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\Viewer;
use App\Models\SearchLogModel;

/**
 * Marketplace search. Always applies VisibilityService first, so search,
 * autocomplete, APIs and exports can never leak restricted listings.
 *
 * Part-number search matches the normalized manufacturer number, OEM number,
 * alternate numbers and supplier-declared cross references. Results record
 * HOW they matched so the UI never implies interchangeability for a
 * cross-reference match.
 */
class SearchService
{
    public const SORTS = [
        'relevance'  => 'Best match',
        'newest'     => 'Newest listings',
        'price_asc'  => 'Price: low to high',
        'price_desc' => 'Price: high to low',
        'stock_desc' => 'Most stock available',
        'age_desc'   => 'Oldest stock first',
    ];

    /**
     * @return array{items:list<array>,total:int,page:int,perPage:int,pages:int}
     */
    public function search(array $f, Viewer $v, int $page = 1, int $perPage = 24, bool $log = true): array
    {
        $db      = db_connect();
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $q       = trim((string) ($f['q'] ?? ''));
        $norm    = normalize_part($q);

        $b = $db->table('products p')
            ->select('p.*, b.name AS brand_name, b.slug AS brand_slug, c.name AS category_name, c.slug AS category_slug,
                co.public_alias, co.legal_name, co.trade_name, co.verification_status, co.public_profile_enabled, co.show_contact_public,
                co.country_code AS supplier_country, co.status AS company_status, co.company_type, co.id AS supplier_id, sp.ranking_score,
                (SELECT path FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.is_primary DESC, pi.sort_order ASC LIMIT 1) AS image_path')
            ->join('brands b', 'b.id = p.brand_id', 'left')
            ->join('categories c', 'c.id = p.category_id')
            ->join('companies co', 'co.id = p.company_id')
            ->join('supplier_profiles sp', 'sp.company_id = co.id', 'left');

        service('visibility')->apply($b, $v);

        $matchExpr = '0';
        if ($q !== '') {
            $like    = $db->escapeLikeString($norm);
            $qEsc    = $db->escape($norm);
            $ftQuery = $db->escape($this->booleanQuery($q));
            $conds   = [];
            if (strlen($norm) >= 2) {
                $conds[] = "p.part_number_norm LIKE '{$like}%' ESCAPE '!'";
                $conds[] = "p.oem_part_number_norm LIKE '{$like}%' ESCAPE '!'";
                $conds[] = "EXISTS (SELECT 1 FROM part_numbers pn WHERE pn.product_id = p.id AND pn.part_number_norm LIKE '{$like}%' ESCAPE '!')";
                $conds[] = "EXISTS (SELECT 1 FROM cross_references xr WHERE xr.product_id = p.id AND xr.ref_part_number_norm LIKE '{$like}%' ESCAPE '!')";
            }
            $conds[] = "MATCH(p.name, p.part_number, p.search_keywords) AGAINST ({$ftQuery} IN BOOLEAN MODE)";
            $conds[] = 'b.name LIKE ' . $db->escape('%' . $db->escapeLikeString($q) . '%') . " ESCAPE '!'";
            $b->where('(' . implode(' OR ', $conds) . ')', null, false);

            $matchExpr = "(CASE
                WHEN p.part_number_norm = {$qEsc} THEN 100
                WHEN p.oem_part_number_norm = {$qEsc} THEN 90
                WHEN EXISTS (SELECT 1 FROM part_numbers pn WHERE pn.product_id = p.id AND pn.part_number_norm = {$qEsc}) THEN 85
                WHEN p.part_number_norm LIKE '{$like}%' ESCAPE '!' THEN 70
                WHEN EXISTS (SELECT 1 FROM cross_references xr WHERE xr.product_id = p.id AND xr.ref_part_number_norm = {$qEsc}) THEN 50
                ELSE 10 + MATCH(p.name, p.part_number, p.search_keywords) AGAINST ({$ftQuery} IN BOOLEAN MODE) END)";
            $b->select("{$matchExpr} AS match_score", false);
            $b->select("(CASE
                WHEN p.part_number_norm LIKE '{$like}%' ESCAPE '!' THEN 'part_number'
                WHEN p.oem_part_number_norm LIKE '{$like}%' ESCAPE '!' THEN 'oem'
                WHEN EXISTS (SELECT 1 FROM part_numbers pn WHERE pn.product_id = p.id AND pn.part_number_norm LIKE '{$like}%' ESCAPE '!') THEN 'alternate'
                WHEN EXISTS (SELECT 1 FROM cross_references xr WHERE xr.product_id = p.id AND xr.ref_part_number_norm LIKE '{$like}%' ESCAPE '!' AND xr.relation = 'verified_interchange') THEN 'verified_cross_reference'
                WHEN EXISTS (SELECT 1 FROM cross_references xr WHERE xr.product_id = p.id AND xr.ref_part_number_norm LIKE '{$like}%' ESCAPE '!') THEN 'declared_cross_reference'
                ELSE 'text' END) AS match_type", false);
        } else {
            $b->select("0 AS match_score, 'none' AS match_type", false);
        }

        $this->applyFilters($b, $f);

        $total = (int) $b->countAllResults(false);

        switch ($f['sort'] ?? 'relevance') {
            case 'newest': $b->orderBy('p.published_at', 'DESC');
                break;

            case 'price_asc': $b->orderBy('p.unit_price IS NULL', '', false)->orderBy('p.unit_price', 'ASC');
                break;

            case 'price_desc': $b->orderBy('p.unit_price', 'DESC');
                break;

            case 'stock_desc': $b->orderBy('(p.stock_on_hand - p.stock_reserved)', 'DESC', false);
                break;

            case 'age_desc': $b->orderBy('p.inventory_age_date', 'ASC');
                break;

            default:
                if ($q !== '') {
                    $b->orderBy('match_score', 'DESC');
                }
                $b->orderBy('sp.ranking_score', 'DESC')->orderBy('p.published_at', 'DESC');
        }
        $b->orderBy('p.id', 'DESC');

        $items = $b->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        if ($log && ($q !== '' || array_filter($f))) {
            model(SearchLogModel::class)->insert([
                'user_id'       => $v->userId,
                'company_id'    => $v->companyId(),
                'query'         => mb_substr($q, 0, 191) ?: null,
                'filters'       => json_encode(array_filter($f, static fn ($x, $k) => $k !== 'q' && $x !== '' && $x !== null, ARRAY_FILTER_USE_BOTH)),
                'results_count' => $total,
                'country_code'  => $v->country,
            ]);
        }

        return ['items' => $items, 'total' => $total, 'page' => $page, 'perPage' => $perPage, 'pages' => (int) max(1, ceil($total / $perPage))];
    }

    private function applyFilters($b, array $f): void
    {
        $db = db_connect();
        if (! empty($f['category'])) {
            $cat = $db->table('categories')->where('slug', $f['category'])->get()->getRowArray();
            if ($cat) {
                $ids = array_merge([(int) $cat['id']], array_map('intval', array_column($db->table('categories')->select('id')->where('parent_id', $cat['id'])->get()->getResultArray(), 'id')));
                $b->whereIn('p.category_id', $ids);
            } else {
                $b->where('1 = 0', null, false);
            }
        }
        if (! empty($f['brand'])) {
            $b->where('b.slug', $f['brand']);
        }
        if (! empty($f['condition'])) {
            $b->where('p.item_condition', $f['condition']);
        }
        if (! empty($f['country']) && preg_match('/^[A-Z]{2}$/', $f['country'])) {
            $b->where('p.warehouse_country', $f['country']);
        }
        if (! empty($f['age']) && ($sql = InventoryService::ageBucketSql($f['age'], 'p.inventory_age_date'))) {
            $b->where($sql, null, false);
        }
        if (! empty($f['verified'])) {
            $b->where("co.verification_status = 'verified' AND EXISTS (SELECT 1 FROM subscriptions s JOIN plan_entitlements pe ON pe.plan_id = s.plan_id AND pe.feature_key = 'verified_badge' AND pe.is_enabled = 1
                WHERE s.company_id = p.company_id AND s.status = 'active' AND CURDATE() BETWEEN s.starts_on AND s.ends_on)", null, false);
        }
        if (isset($f['min_price']) && is_numeric($f['min_price'])) {
            $b->where('p.unit_price >=', (float) $f['min_price']);
        }
        if (isset($f['max_price']) && is_numeric($f['max_price'])) {
            $b->where('p.unit_price <=', (float) $f['max_price']);
        }
        if (isset($f['min_qty']) && is_numeric($f['min_qty'])) {
            $b->where('(p.stock_on_hand - p.stock_reserved) >=', (int) $f['min_qty']);
        }
        if (! empty($f['in_stock'])) {
            $b->where('(p.stock_on_hand - p.stock_reserved) >', 0);
        }
        foreach (['id' => 'inner_diameter_mm', 'od' => 'outer_diameter_mm', 'w' => 'width_mm'] as $k => $col) {
            if (isset($f[$k]) && is_numeric($f[$k])) {
                $tol = 0.05;
                $b->where("p.{$col} BETWEEN " . ((float) $f[$k] - $tol) . ' AND ' . ((float) $f[$k] + $tol), null, false);
            }
        }
        if (! empty($f['make'])) {
            $sub = 'EXISTS (SELECT 1 FROM fitments ft WHERE ft.product_id = p.id AND ft.make = ' . $db->escape($f['make']);
            if (! empty($f['model'])) {
                $sub .= ' AND ft.model = ' . $db->escape($f['model']);
            }
            if (! empty($f['year']) && ctype_digit((string) $f['year'])) {
                $y = (int) $f['year'];
                $sub .= " AND (ft.year_from IS NULL OR ft.year_from <= {$y}) AND (ft.year_to IS NULL OR ft.year_to >= {$y})";
            }
            $b->where($sub . ')', null, false);
        }
        if (! empty($f['spec'])) {
            $b->where('EXISTS (SELECT 1 FROM product_specifications ps WHERE ps.product_id = p.id AND (ps.spec_value LIKE ' . $db->escape('%' . $db->escapeLikeString($f['spec']) . '%') . " ESCAPE '!' OR ps.spec_name LIKE " . $db->escape('%' . $db->escapeLikeString($f['spec']) . '%') . " ESCAPE '!'))", null, false);
        }
        if (! empty($f['supplier_id']) && ctype_digit((string) $f['supplier_id'])) {
            $b->where('p.company_id', (int) $f['supplier_id']);
        }
    }

    private function booleanQuery(string $q): string
    {
        $words = preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $q) ?? '') ?: [];
        $words = array_filter($words, static fn ($w) => mb_strlen($w) >= 3);

        return $words ? implode(' ', array_map(static fn ($w) => '+' . $w . '*', $words)) : '""';
    }

    /**
     * Autocomplete suggestions (part numbers, product names, brands).
     *
     * @return list<array{label:string,type:string,url:string}>
     */
    public function suggest(string $q, Viewer $v, int $limit = 8): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $res = $this->search(['q' => $q], $v, 1, $limit, false);
        $out = [];
        foreach ($res['items'] as $p) {
            $out[] = ['label' => $p['part_number'] . ' — ' . $p['name'], 'type' => $p['match_type'] === 'declared_cross_reference' ? 'Cross-reference (unverified)' : 'Part', 'url' => site_url('product/' . $p['slug'])];
        }
        $brands = db_connect()->table('brands')->select('name, slug')->where('is_active', 1)
            ->like('name', $q, 'after')->limit(3)->get()->getResultArray();
        foreach ($brands as $b) {
            $out[] = ['label' => $b['name'], 'type' => 'Brand', 'url' => site_url('marketplace?brand=' . $b['slug'])];
        }

        return array_slice($out, 0, $limit);
    }

    /**
     * Related inventory: same category/brand, visible to the viewer.
     */
    public function related(array $product, Viewer $v, int $limit = 4): array
    {
        $b = db_connect()->table('products p')
            ->select('p.*, b.name AS brand_name, co.public_alias, co.legal_name, co.trade_name, co.verification_status, co.public_profile_enabled, co.show_contact_public,
                co.status AS company_status, co.company_type, co.id AS supplier_id,
                (SELECT path FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.is_primary DESC, pi.sort_order LIMIT 1) AS image_path')
            ->join('brands b', 'b.id = p.brand_id', 'left')
            ->join('companies co', 'co.id = p.company_id')
            ->where('p.id !=', $product['id'])
            ->where('p.category_id', $product['category_id']);
        service('visibility')->apply($b, $v);

        return $b->orderBy('p.brand_id = ' . (int) ($product['brand_id'] ?? 0), 'DESC', false)->orderBy('p.published_at', 'DESC')->limit($limit)->get()->getResultArray();
    }

    /**
     * Other visible listings with the same part number (for supplier comparison).
     * Same part number = same manufacturer reference; cross-references are listed separately.
     */
    public function sameParts(array $product, Viewer $v): array
    {
        $b = db_connect()->table('products p')
            ->select('p.*, b.name AS brand_name, co.public_alias, co.legal_name, co.trade_name, co.verification_status, co.public_profile_enabled, co.show_contact_public, co.status AS company_status, co.company_type, co.id AS supplier_id')
            ->join('brands b', 'b.id = p.brand_id', 'left')
            ->join('companies co', 'co.id = p.company_id')
            ->where('p.part_number_norm', $product['part_number_norm']);
        if ($product['brand_id']) {
            $b->where('p.brand_id', $product['brand_id']);
        }
        service('visibility')->apply($b, $v);

        return $b->orderBy('p.unit_price', 'ASC')->limit(20)->get()->getResultArray();
    }
}
