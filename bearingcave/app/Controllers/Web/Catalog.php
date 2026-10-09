<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Services\InventoryService;
use App\Services\ProductService;
use App\Services\SearchService;
use CodeIgniter\HTTP\ResponseInterface;

class Catalog extends BaseController
{
    private const FILTERS = ['q', 'category', 'brand', 'condition', 'country', 'age', 'verified', 'min_price', 'max_price', 'min_qty', 'in_stock', 'id', 'od', 'w', 'make', 'model', 'year', 'spec', 'sort'];

    public function index(): string
    {
        $f = [];
        foreach (self::FILTERS as $k) {
            $v = $this->request->getGet($k);
            if (is_string($v) && trim($v) !== '') {
                $f[$k] = mb_substr(trim($v), 0, 100);
            }
        }
        $page   = max(1, (int) $this->request->getGet('page'));
        $viewer = service('visibility')->current();
        $result = service('search')->search($f, $viewer, $page, 24);

        $category = ! empty($f['category']) ? db_connect()->table('categories')->where('slug', $f['category'])->get()->getRowArray() : null;
        $brand    = ! empty($f['brand']) ? db_connect()->table('brands')->where('slug', $f['brand'])->get()->getRowArray() : null;
        $heading  = $category['name'] ?? ($brand ? $brand['name'] . ' parts' : (isset($f['q']) ? 'Results for “' . $f['q'] . '”' : 'Automotive surplus inventory'));

        $hasQuery = count(array_diff(array_keys($f), ['category', 'brand', 'page'])) > 0;

        return view('web/catalog', [
            'title'      => $heading,
            'metaDescription' => $category['meta_description'] ?? ('Search genuine surplus ' . ($category['name'] ?? 'automotive parts') . ' by part number, OEM number, brand and specification on BearingCave.'),
            'canonical'  => $category ? site_url('category/' . $category['slug']) : ($brand ? site_url('brand/' . $brand['slug']) : site_url('marketplace')),
            'robots'     => $hasQuery || $page > 1 ? 'noindex,follow' : 'index,follow',
            'heading'    => $heading,
            'filters'    => $f,
            'result'     => $result,
            'categories' => db_connect()->table('categories')->where('is_active', 1)->orderBy('parent_id IS NOT NULL', '', false)->orderBy('sort_order')->get()->getResultArray(),
            'brands'     => array_column(db_connect()->table('brands')->select('slug, name')->where('is_active', 1)->orderBy('name')->get()->getResultArray(), 'name', 'slug'),
            'sorts'      => SearchService::SORTS,
            'conditions' => ProductService::CONDITIONS,
            'ages'       => InventoryService::AGE_BUCKETS,
            'makes'      => array_column(db_connect()->table('fitments')->select('make')->distinct()->orderBy('make')->get()->getResultArray(), 'make'),
            'viewer'     => $viewer,
        ]);
    }

    public function category(string $slug)
    {
        if (! db_connect()->table('categories')->where('slug', $slug)->countAllResults()) {
            $this->notFound();
        }
        $this->request->setGlobal('get', array_merge($this->request->getGet() ?? [], ['category' => $slug]));

        return $this->index();
    }

    public function brand(string $slug)
    {
        if (! db_connect()->table('brands')->where('slug', $slug)->countAllResults()) {
            $this->notFound();
        }
        $this->request->setGlobal('get', array_merge($this->request->getGet() ?? [], ['brand' => $slug]));

        return $this->index();
    }

    public function suggest(): ResponseInterface
    {
        $q = mb_substr(trim((string) $this->request->getGet('q')), 0, 60);

        return $this->response->setJSON(['data' => service('search')->suggest($q, service('visibility')->current())])
            ->setHeader('Cache-Control', 'private, max-age=60');
    }
}
