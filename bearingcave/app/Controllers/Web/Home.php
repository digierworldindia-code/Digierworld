<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;

class Home extends BaseController
{
    public function index(): string
    {
        $viewer = service('visibility')->current();
        $latest = service('search')->search(['sort' => 'newest'], $viewer, 1, 8, false);
        $cats   = db_connect()->table('categories c')
            ->select('c.*, (SELECT COUNT(*) FROM products p JOIN categories cc ON cc.id = p.category_id WHERE (cc.id = c.id OR cc.parent_id = c.id) AND p.status = \'published\' AND p.deleted_at IS NULL) AS product_count', false)
            ->where('c.parent_id', null)->where('c.is_active', 1)->orderBy('c.sort_order')->get()->getResultArray();
        $stats = db_connect()->query("SELECT
            (SELECT COUNT(*) FROM products WHERE status='published' AND deleted_at IS NULL) AS listings,
            (SELECT COUNT(*) FROM companies WHERE company_type='supplier' AND verification_status='verified' AND deleted_at IS NULL) AS verified_suppliers,
            (SELECT COUNT(DISTINCT warehouse_country) FROM products WHERE status='published' AND deleted_at IS NULL) AS countries")->getRowArray();

        return view('web/home', [
            'title'     => '',
            'canonical' => site_url('/'),
            'latest'    => $latest['items'],
            'categories' => $cats,
            'stats'     => $stats,
            'jsonLd'    => [
                '@context' => 'https://schema.org',
                '@graph'   => [
                    ['@type' => 'Organization', 'name' => 'BearingCave', 'url' => 'https://www.bearingcave.com', 'logo' => base_url('assets/images/favicon.svg')],
                    ['@type' => 'WebSite', 'name' => 'BearingCave', 'url' => 'https://www.bearingcave.com', 'potentialAction' => ['@type' => 'SearchAction', 'target' => site_url('marketplace') . '?q={search_term_string}', 'query-input' => 'required name=search_term_string']],
                ],
            ],
        ]);
    }
}
