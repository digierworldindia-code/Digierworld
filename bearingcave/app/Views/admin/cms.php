<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'CMS pages', 'actions' => '<a class="btn btn-sm btn-primary" href="' . site_url('admin/cms/new') . '">New page</a>']) ?>
<div class="bc-card"><div class="table-responsive"><table class="table table-bc table-stack mb-0"><thead><tr><th>Page</th><th>URL</th><th>Status</th><th>Approval</th><th>Footer</th><th>Updated</th></tr></thead><tbody>
<?php foreach ($rows as $p): ?><tr><td data-label="Page"><a href="<?= site_url('admin/cms/' . $p['id']) ?>"><?= esc($p['title']) ?></a></td><td data-label="URL" class="small"><a href="<?= site_url('page/' . $p['slug']) ?>" target="_blank">/page/<?= esc($p['slug']) ?></a></td><td data-label="Status"><?= status_badge($p['status']) ?></td><td data-label="Approval"><?= $p['approval_status'] === 'approved' ? status_badge('approved') : pending_badge('Pending legal approval') ?></td><td data-label="Footer"><?= $p['show_in_footer'] ? 'Yes' : 'No' ?></td><td data-label="Updated" class="small"><?= fdt($p['updated_at']) ?></td></tr><?php endforeach ?>
</tbody></table></div></div>
<?= $this->endSection() ?>
