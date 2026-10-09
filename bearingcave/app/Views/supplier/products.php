<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Inventory', 'subtitle' => $limit !== null ? 'Your plan allows ' . $limit . ' active listings.' : 'Manage listings, stock lots, pricing and visibility.',
    'actions' => '<a class="btn btn-light btn-sm" href="' . site_url('supplier/products/export') . '"><i class="bi bi-download me-1" aria-hidden="true"></i>Inventory report (CSV)</a>' . (entitled('bulk_import') ? '<a class="btn btn-light btn-sm" href="' . site_url('supplier/imports') . '"><i class="bi bi-upload me-1" aria-hidden="true"></i>Bulk upload</a>' : '') . '<a class="btn btn-primary btn-sm" href="' . site_url('supplier/products/new') . '"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add inventory</a>']) ?>
<form class="row g-2 mb-3" method="get">
    <div class="col-md-4"><label class="visually-hidden" for="pq">Search</label><input id="pq" class="form-control form-control-sm" type="search" name="q" value="<?= esc((string) service('request')->getGet('q'), 'attr') ?>" placeholder="Search SKU, part number or name"></div>
    <div class="col-md-3"><label class="visually-hidden" for="ps">Status</label><select id="ps" class="form-select form-select-sm" name="status"><option value="">Active (not archived)</option><?php foreach (['draft', 'pending_review', 'published', 'rejected', 'unpublished', 'archived'] as $s): ?><option value="<?= $s ?>"<?= service('request')->getGet('status') === $s ? ' selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option><?php endforeach ?></select></div>
    <div class="col-md-3"><label class="visually-hidden" for="pa">Age</label><select id="pa" class="form-select form-select-sm" name="age"><option value="">Any inventory age</option><?php foreach ($ages as $k => $l): ?><option value="<?= $k ?>"<?= service('request')->getGet('age') === $k ? ' selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select></div>
    <div class="col-md-2"><button class="btn btn-sm btn-light w-100" type="submit">Filter</button></div>
</form>
<div class="bc-card">
<?php if (! $products): ?><?= empty_state('bi-boxes', 'No inventory found', 'Add your first surplus lot — it stays private until you submit it.', '<a class="btn btn-primary btn-sm" href="' . site_url('supplier/products/new') . '">Add inventory</a>') ?><?php else: ?>
    <div class="table-responsive"><table class="table table-bc table-stack mb-0">
        <thead><tr><th>Product</th><th>Category</th><th class="text-end">On hand</th><th class="text-end">Reserved</th><th>Price</th><th>Age</th><th>Visibility</th><th>Status</th><th></th></tr></thead>
        <tbody><?php foreach ($products as $p): ?><tr>
            <td data-label="Product"><a class="fw-semibold" href="<?= site_url('supplier/products/' . $p['id'] . '/edit') ?>"><span class="part-no"><?= esc($p['part_number']) ?></span></a><div class="small"><?= esc($p['name']) ?></div><div class="small text-muted">SKU <?= esc($p['sku']) ?> · <?= esc($p['brand_name'] ?? 'Unbranded') ?></div></td>
            <td data-label="Category" class="small"><?= esc($p['category_name']) ?></td>
            <td data-label="On hand" class="text-end"><?= number_format((int) $p['stock_on_hand']) ?></td><td data-label="Reserved" class="text-end"><?= number_format((int) $p['stock_reserved']) ?></td>
            <td data-label="Price" class="small"><?= $p['price_visibility'] === 'on_request' ? 'On request' : ($p['sale_mode'] === 'lot' ? money($p['lot_price'], $p['currency']) . ' / lot' : money($p['unit_price'], $p['currency'], 2) . ' / pc') ?></td>
            <td data-label="Age" class="small"><?= age_label($p['inventory_age_date']) ?></td>
            <td data-label="Visibility" class="small"><?= esc(['public' => 'All buyers', 'verified_buyers' => 'Verified buyers', 'hidden' => 'Hidden (RFQ only)'][$p['visibility']]) ?><?= $p['country_mode'] !== 'all' ? '<br><span class="text-muted">' . ($p['country_mode'] === 'allow_list' ? 'Selected countries' : 'Excluded countries') . '</span>' : '' ?></td>
            <td data-label="Status"><?= status_badge($p['status']) ?></td>
            <td><a class="btn btn-sm btn-light" href="<?= site_url('supplier/products/' . $p['id'] . '/edit') ?>">Manage</a></td>
        </tr><?php endforeach ?></tbody>
    </table></div>
    <?= $pager->links() ?>
<?php endif ?>
</div>
<?= $this->endSection() ?>
