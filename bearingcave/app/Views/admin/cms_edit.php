<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => $title, 'breadcrumbs' => ['CMS pages' => 'admin/cms', ($page['title'] ?? 'New') => null]]) ?>
<form method="post" action="<?= site_url('admin/cms' . ($page ? '/' . $page['id'] : '')) ?>" novalidate><?= csrf_field() ?>
<div class="row g-4"><div class="col-xl-8"><div class="bc-card"><div class="bc-card-body">
    <?= field('title', 'Title', $page['title'] ?? '', ['required' => true]) ?>
    <?= textarea_field('body', 'Body (HTML: p, h2–h4, lists, links, tables)', $page['body'] ?? '', ['rows' => 18, 'required' => true, 'help' => 'Scripts, styles and event attributes are removed on save.']) ?>
</div></div></div>
<div class="col-xl-4"><div class="bc-card"><div class="bc-card-body">
    <?= field('slug', 'URL slug', $page['slug'] ?? '', ['required' => true, 'prepend' => '/page/']) ?>
    <?= select_field('status', 'Status', ['draft' => 'Draft', 'published' => 'Published'], $page['status'] ?? 'draft') ?>
    <?= select_field('approval_status', 'Legal / client approval', ['pending_approval' => 'Pending approval (shows draft notice)', 'approved' => 'Approved'], $page['approval_status'] ?? 'pending_approval') ?>
    <?= check_field('show_in_footer', 'Show link in footer', (bool) ($page['show_in_footer'] ?? 0)) ?>
    <?= field('meta_title', 'Meta title', $page['meta_title'] ?? '') ?><?= textarea_field('meta_description', 'Meta description', $page['meta_description'] ?? '', ['rows' => 2]) ?>
    <button class="btn btn-primary w-100" type="submit">Save page</button>
</div></div></div></div>
</form>
<?= $this->endSection() ?>
