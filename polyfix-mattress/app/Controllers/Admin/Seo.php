<?php

namespace App\Controllers\Admin;

use App\Exceptions\AppException;
use App\Libraries\Audit;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Search-engine metadata for every indexable page. What is stored here is what
 * the public pages render — the code supplies a fallback only when a row is
 * missing, so SEO copy is a content change, not a deploy.
 */
class Seo extends AdminController
{
    public function index(): string
    {
        $rows = db_connect()->table('seo_metadata')->orderBy('scope')->orderBy('entity_key')->get()->getResultArray();
        foreach ($rows as &$row) {
            $row['path'] = $row['canonical_path'] ?? match ($row['scope']) {
                'PRODUCT' => '/mattresses/' . $row['entity_key'],
                'PAGE'    => $row['entity_key'] === 'home' ? '/' : '/' . $row['entity_key'],
                default   => null,
            };
            $row['keywords_text'] = implode(', ', json_decode((string) $row['keywords'], true) ?: []);
        }
        unset($row);

        return $this->render('admin/seo', 'SEO', 'admin/seo', [
            'rows'     => $rows,
            'indexing' => \App\Libraries\Settings::flag('seo.robots_allow_indexing'),
            'sitemap'  => \App\Libraries\Settings::flag('seo.sitemap_enabled'),
        ]);
    }

    public function save(string $id): RedirectResponse
    {
        $in = [
            'title'              => $this->post('title', 200),
            'description'        => $this->post('description', 400),
            'canonical_path'     => $this->post('canonical_path', 300),
            'og_title'           => $this->post('og_title', 200),
            'og_description'     => $this->post('og_description', 400),
            'og_image_url'       => $this->post('og_image_url', 500),
            'keywords'           => $this->post('keywords', 500),
            'robots_index'       => $this->request->getPost('robots_index') === '1',
            'robots_follow'      => $this->request->getPost('robots_follow') === '1',
            'sitemap_include'    => $this->request->getPost('sitemap_include') === '1',
            'sitemap_priority'   => (string) $this->request->getPost('sitemap_priority'),
            'sitemap_changefreq' => (string) $this->request->getPost('sitemap_changefreq'),
        ];

        return $this->act(function () use ($id, $in): void {
            if (mb_strlen($in['title']) < 5 || mb_strlen($in['description']) < 20) {
                throw AppException::rule('A title and a description of at least 20 characters are needed — they are what appears in search results.');
            }
            if ($in['canonical_path'] !== '' && ! str_starts_with($in['canonical_path'], '/')) {
                throw AppException::rule('The canonical path must start with "/".');
            }
            if (! in_array($in['sitemap_changefreq'], ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'], true)) {
                throw AppException::rule('Choose a valid change frequency.');
            }
            $priority = (float) $in['sitemap_priority'];
            if ($priority < 0 || $priority > 1) {
                throw AppException::rule('Priority runs from 0.0 to 1.0.');
            }

            $db     = db_connect();
            $before = $db->table('seo_metadata')->where('id', $id)->get()->getRowArray() ?? throw AppException::notFound('SEO record');
            $keywords = array_values(array_filter(array_map('trim', explode(',', $in['keywords']))));

            $db->table('seo_metadata')->where('id', $id)->update([
                'title'              => $in['title'],
                'description'        => $in['description'],
                'canonical_path'     => $in['canonical_path'] !== '' ? $in['canonical_path'] : null,
                'og_title'           => $in['og_title'] !== '' ? $in['og_title'] : null,
                'og_description'     => $in['og_description'] !== '' ? $in['og_description'] : null,
                'og_image_url'       => $in['og_image_url'] !== '' ? $in['og_image_url'] : null,
                'keywords'           => json_encode($keywords, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'robots_index'       => $in['robots_index'] ? 1 : 0,
                'robots_follow'      => $in['robots_follow'] ? 1 : 0,
                'sitemap_include'    => $in['sitemap_include'] ? 1 : 0,
                'sitemap_priority'   => number_format($priority, 1),
                'sitemap_changefreq' => $in['sitemap_changefreq'],
                'updated_by'         => $this->ctx->userId(),
            ]);
            Audit::instance()->record('SEO_UPDATED', 'seo_metadata', $id,
                ['title' => $before['title'], 'robotsIndex' => (bool) $before['robots_index']],
                ['title' => $in['title'], 'robotsIndex' => $in['robots_index'], 'key' => $before['scope'] . ':' . $before['entity_key']]);
        }, 'SEO saved.', site_url('admin/seo'));
    }
}
