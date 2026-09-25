<?php

namespace App\Controllers\Site;

use App\Controllers\BaseController;
use App\Libraries\Seo as SeoLib;
use App\Libraries\Settings;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * sitemap.xml and robots.txt.
 *
 * The sitemap is built from the SEO records an administrator controls: a page
 * marked noindex, or excluded from the sitemap in the console, disappears from
 * here too. Nothing private (admin, dealer portal, account, media) is listed.
 */
class Seo extends BaseController
{
    public function sitemap(): ResponseInterface
    {
        if (! Settings::flag('seo.sitemap_enabled') || ! Settings::flag('seo.robots_allow_indexing')) {
            return $this->response->setStatusCode(404)->setContentType('text/plain')->setBody('Not found');
        }

        $db   = db_connect();
        $seo  = [];
        foreach ($db->table('seo_metadata')->get()->getResultArray() as $row) {
            $seo[$row['scope'] . ':' . $row['entity_key']] = $row;
        }

        $candidates = [];
        foreach ($db->table('website_pages')->select('slug, updated_at')->where('status', 'PUBLISHED')->get()->getResultArray() as $p) {
            $candidates[] = ['PAGE:' . $p['slug'], $p['slug'] === 'home' ? '/' : '/' . $p['slug'], $p['updated_at'], '0.7', 'monthly'];
        }
        $productsUpdated = null;
        foreach ($db->table('products p')->select('p.slug, GREATEST(p.updated_at, COALESCE(wp.updated_at, p.updated_at)) AS updated_at', false)
            ->join('website_products wp', 'wp.product_id = p.id')
            ->where(['p.status' => 'PUBLISHED', 'p.deleted_at' => null, 'wp.is_published' => 1])->get()->getResultArray() as $p) {
            $candidates[]    = ['PRODUCT:' . $p['slug'], '/mattresses/' . $p['slug'], $p['updated_at'], '0.8', 'monthly'];
            $productsUpdated = max($productsUpdated ?? $p['updated_at'], $p['updated_at']);
        }
        // Pages with no CMS row of their own.
        $candidates[] = ['PAGE:mattresses', '/mattresses', $productsUpdated ?? utc_now(), '0.9', 'weekly'];

        $entries = [];
        foreach ($candidates as [$key, $path, $updated, $priority, $freq]) {
            $meta = $seo[$key] ?? null;
            if ($meta !== null && (! $meta['sitemap_include'] || ! $meta['robots_index'])) {
                continue;
            }
            $entries[] = [
                'loc'        => SeoLib::absolute($meta['canonical_path'] ?? $path),
                'lastmod'    => gmdate('Y-m-d\TH:i:s\Z', strtotime($updated . ' UTC')),
                'changefreq' => $meta['sitemap_changefreq'] ?? $freq,
                'priority'   => number_format((float) ($meta['sitemap_priority'] ?? $priority), 1),
            ];
        }

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        foreach ($entries as $e) {
            $xml->startElement('url');
            foreach ($e as $k => $v) {
                $xml->writeElement($k, $v);
            }
            $xml->endElement();
        }
        $xml->endElement();

        return $this->response->setContentType('application/xml', 'UTF-8')
            ->setHeader('Cache-Control', 'public, max-age=3600')
            ->setBody($xml->outputMemory());
    }

    public function robots(): ResponseInterface
    {
        $root = rtrim(SeoLib::absolute('/'), '/');

        // A staging copy switches indexing off and the whole site closes.
        if (! Settings::flag('seo.robots_allow_indexing')) {
            $body = "User-agent: *\nDisallow: /\n";
        } else {
            // Disallow is a request, not a control: the private areas also send
            // X-Robots-Tag: noindex and refuse to serve anything without a session.
            $body = implode("\n", [
                'User-agent: *',
                'Allow: /',
                'Disallow: /admin',
                'Disallow: /dealer$',
                'Disallow: /dealer/',
                'Disallow: /account',
                'Disallow: /media/',
                'Disallow: /login',
                'Disallow: /forgot-password',
                'Disallow: /reset-password',
                '# Carries a token identifying one mattress.',
                'Disallow: /warranty/verify',
                '# Filtered dealer views are the same content in a different order.',
                'Disallow: /dealers?',
                '',
                'User-agent: AhrefsBot',
                'User-agent: SemrushBot',
                'User-agent: MJ12bot',
                'User-agent: DotBot',
                'Allow: /',
                'Crawl-delay: 10',
                '',
                'Sitemap: ' . $root . '/sitemap.xml',
                '',
            ]);
        }

        return $this->response->setContentType('text/plain', 'UTF-8')
            ->setHeader('Cache-Control', 'public, max-age=3600')->setBody($body);
    }
}
