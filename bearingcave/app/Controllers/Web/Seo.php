<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\Viewer;

/**
 * robots.txt and XML sitemap. The sitemap is built with a guest Viewer, so
 * confidential / country-restricted / verified-only listings never appear.
 */
class Seo extends BaseController
{
    public function robots()
    {
        $lines = ['User-agent: *'];
        if (! policy('seo.indexing_enabled', false)) {
            $lines[] = 'Disallow: /';
        } else {
            foreach (['/admin', '/buyer', '/supplier', '/api', '/account', '/documents', '/notifications', '/compare', '/login', '/register/', '/search/'] as $p) {
                $lines[] = 'Disallow: ' . $p;
            }
            $lines[] = 'Sitemap: ' . site_url('sitemap.xml');
        }

        return $this->response->setContentType('text/plain')->setBody(implode("\n", $lines) . "\n");
    }

    public function sitemap()
    {
        $urls = [site_url('/'), site_url('marketplace'), site_url('suppliers'), site_url('membership'), site_url('how-it-works'), site_url('for-buyers'),
            site_url('for-suppliers'), site_url('services/inspection'), site_url('services/logistics'), site_url('trust-and-verification'), site_url('about'), site_url('contact')];
        $db = db_connect();
        foreach ($db->table('categories')->select('slug')->where('is_active', 1)->get()->getResultArray() as $c) {
            $urls[] = site_url('category/' . $c['slug']);
        }
        foreach ($db->table('cms_pages')->select('slug')->where('status', 'published')->get()->getResultArray() as $c) {
            $urls[] = site_url('page/' . $c['slug']);
        }
        $b = $db->table('products p')->select('p.slug, p.updated_at');
        service('visibility')->apply($b, Viewer::guest());
        $products = $b->orderBy('p.id', 'DESC')->limit(45000)->get()->getResultArray();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $xml .= '<url><loc>' . esc($u) . '</loc></url>' . "\n";
        }
        foreach ($products as $p) {
            $xml .= '<url><loc>' . esc(site_url('product/' . $p['slug'])) . '</loc>' . ($p['updated_at'] ? '<lastmod>' . date('Y-m-d', strtotime($p['updated_at'])) . '</lastmod>' : '') . '</url>' . "\n";
        }

        return $this->response->setContentType('application/xml')->setBody($xml . '</urlset>');
    }
}
