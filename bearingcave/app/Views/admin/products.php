<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Product review']) ?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach (['pending_review' => 'Awaiting review', 'published' => 'Published', 'rejected' => 'Rejected', 'draft' => 'Draft', 'unpublished' => 'Unpublished', 'archived' => 'Archived', '' => 'All'] as $k => $l): ?><a class="btn btn-sm <?= $status === $k ? 'btn-primary' : 'btn-light' ?>" href="<?= site_url('admin/products?status=' . $k) ?>"><?= $l ?></a><?php endforeach ?>
    <form class="ms-auto d-flex gap-1" method="get"><input type="hidden" name="status" value="<?= esc($status, 'attr') ?>"><label class="visually-hidden" for="pq">Search</label><input id="pq" class="form-control form-control-sm" name="q" placeholder="Part number or supplier" value="<?= esc((string) service('request')->getGet('q'), 'attr') ?>"><button class="btn btn-sm btn-light">Search</button></form>
</div>
<div class="bc-card"><?php if (! $rows): ?><?= empty_state('bi-check2-all', 'Nothing here') ?><?php else: ?>
<div class="table-responsive"><table class="table table-bc table-stack mb-0"><thead><tr><th>Product</th><th>Supplier</th><th>Category</th><th class="text-end">Stock</th><th>Visibility</th><th>Status</th><th>Updated</th></tr></thead><tbody>
<?php foreach ($rows as $p): ?><tr><td data-label="Product"><a class="fw-semibold" href="<?= site_url('admin/products/' . $p['id']) ?>"><span class="part-no"><?= esc($p['part_number']) ?></span></a> <?= esc($p['name']) ?> <?= sample_badge($p) ?></td><td data-label="Supplier" class="small"><?= esc($p['legal_name']) ?></td><td data-label="Category" class="small"><?= esc($p['category_name']) ?></td><td data-label="Stock" class="text-end"><?= number_format((int) $p['stock_on_hand']) ?></td><td data-label="Visibility" class="small"><?= esc($p['visibility']) ?> · <?= esc($p['country_mode']) ?></td><td data-label="Status"><?= status_badge($p['status']) ?></td><td data-label="Updated" class="small"><?= fdt($p['updated_at']) ?></td></tr><?php endforeach ?>
</tbody></table></div><?= $pager->links() ?><?php endif ?></div>
<?= $this->endSection() ?>
