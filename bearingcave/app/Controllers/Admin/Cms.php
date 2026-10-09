<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\CmsPageModel;

class Cms extends AdminController
{
    public function index(): string
    {
        return $this->page('cms', ['title' => 'CMS pages', 'rows' => model(CmsPageModel::class)->orderBy('slug')->findAll()]);
    }

    public function edit(?int $id = null): string
    {
        $page = $id ? (model(CmsPageModel::class)->find($id) ?? $this->notFound()) : null;

        return $this->page('cms_edit', ['title' => $page ? 'Edit page' : 'New page', 'page' => $page]);
    }

    public function save(?int $id = null)
    {
        if ($r = $this->invalid(['title' => 'required|max_length[191]', 'slug' => 'required|alpha_dash|max_length[120]', 'body' => 'required', 'status' => 'required|in_list[draft,published]', 'meta_description' => 'permit_empty|max_length[255]'])) {
            return $r;
        }
        $m    = model(CmsPageModel::class);
        $slug = strtolower((string) $this->request->getPost('slug'));
        $dupe = $m->where('slug', $slug);
        if ($id) {
            $dupe->where('id !=', $id);
        }
        if ($dupe->countAllResults() > 0) {
            return redirect()->back()->withInput()->with('error', 'That URL slug is already used.');
        }
        $data = ['title' => $this->request->getPost('title'), 'slug' => $slug, 'body' => $this->sanitize((string) $this->request->getPost('body')),
            'meta_title' => $this->request->getPost('meta_title') ?: null, 'meta_description' => $this->request->getPost('meta_description') ?: null,
            'status' => $this->request->getPost('status'), 'show_in_footer' => $this->request->getPost('show_in_footer') === '1' ? 1 : 0,
            'approval_status' => $this->request->getPost('approval_status') === 'approved' ? 'approved' : 'pending_approval', 'updated_by' => $this->userId()];
        $id ? $m->update($id, $data) : ($id = (int) $m->insert($data));
        service('audit')->log('cms.saved', ['entity_type' => 'cms_page', 'entity_id' => $id, 'description' => $slug]);

        return redirect()->to(site_url('admin/cms/' . $id))->with('success', 'Page saved.');
    }

    /**
     * Allows a safe subset of HTML for page bodies (no scripts, styles, event handlers or javascript: URLs).
     */
    private function sanitize(string $html): string
    {
        $html = strip_tags($html, '<p><h2><h3><h4><ul><ol><li><strong><em><a><br><table><thead><tbody><tr><th><td><blockquote><hr>');
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s+style\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $html) ?? '';

        return preg_replace('/href\s*=\s*(["\'])\s*(javascript|data|vbscript):[^"\']*\1/i', 'href="#"', $html) ?? '';
    }
}
