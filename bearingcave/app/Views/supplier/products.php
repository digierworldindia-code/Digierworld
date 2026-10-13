<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Inventory', 'subtitle' => $limit !== null ? 'Your plan allows ' . $limit . ' active listings.' : 'Manage listings, stock lots, pricing and visibility.',
    'actions' => '<a class="btn btn-light btn-sm" href="' . site_url('supplier/products/export') . '"><i class="bi bi-download me-1" aria-hidden="true"></i>Inventory report (CSV)</a>' . (entitled('bulk_import') ? '<a class="btn btn-light btn-sm" href="' . site_url('supplier/imports') . '"><i class="bi bi-upload me-1" aria-hidden="true"></i>Bulk upload</a>' : '') . '<a class="btn btn-primary btn-sm" href="' . site_url('supplier/products/new') . '"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add inventory</a>']) ?>
<form class="row g-2 mb-3" method="get">
    <div class="col-md-4"><label class="visually-hidden" for="pq">Search</label><input id="pq" class="form-control form-control-sm" type="search" name="q" value="<?= esc((string) service('request')->getGet('q'), 'attr') ?>" placeholder="Search SKU, part number or name"></div>
    <div class="col-md-3"><label class="visually-hidden" for="ps">Status</label><select id="ps" class="form-select form-select-sm" name="status"><option value="">Active (not archived)</option><?php foreach (['draft', 'pending_review', 'published', 'rejected', 'unpublished', 'archived'] as $s): ?><option value="<?= $s ?>"<?= service('request')->getGet('status') === $s ? ' selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option><?php endforeach ?></select></div>
    <div class="col-md-2"><label class="visually-hidden" for="pa">Age</label><select id="pa" class="form-select form-select-sm" name="age"><option value="">Any inventory age</option><?php foreach ($ages as $k => $l): ?><option value="<?= $k ?>"<?= service('request')->getGet('age') === $k ? ' selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select></div>
    <div class="col-md-1 d-flex align-items-center"><div class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="clearance" value="1" id="pcl"<?= service('request')->getGet('clearance') === '1' ? ' checked' : '' ?>><label class="form-check-label" for="pcl">Clearance</label></div></div>
    <div class="col-md-2"><button class="btn btn-sm btn-light w-100" type="submit">Filter</button></div>
</form>
<div class="bc-card">
<?php if (! $products): ?><?= empty_state('bi-boxes', 'No inventory found', 'Add your first surplus lot — it stays private until you submit it.', '<a class="btn btn-primary btn-sm" href="' . site_url('supplier/products/new') . '">Add inventory</a>') ?><?php else: ?>
    <?php $canManage = service('companyContext')->can('inventory.manage'); ?>
    <?php if ($canManage): ?>
    <form id="bulkForm" method="post" action="<?= site_url('supplier/products/bulk') ?>" class="d-flex flex-wrap gap-2 align-items-center p-3 border-bottom" data-bulk>
        <?= csrf_field() ?>
        <span class="small text-muted me-1"><span data-bulk-count>0</span> selected</span>
        <label class="visually-hidden" for="bulkAction">Bulk action</label>
        <select id="bulkAction" class="form-select form-select-sm w-auto" name="action" required><option value="">Bulk action…</option><?php foreach (\App\Services\ProductService::BULK_ACTIONS as $k => $l): ?><option value="<?= $k ?>"><?= esc($l) ?></option><?php endforeach ?></select>
        <label class="visually-hidden" for="bulkPct">Clearance discount %</label>
        <div class="input-group input-group-sm w-auto"><input id="bulkPct" class="form-control" type="number" name="clearance_pct" min="1" max="90" step="0.5" placeholder="Discount" style="max-width:6.5rem"><span class="input-group-text">%</span></div>
        <button class="btn btn-sm btn-primary" type="submit" data-confirm="Apply this action to the selected listings?">Apply</button>
        <span class="small text-muted ms-md-auto">Liquidation tip: filter by age “More than 12 months”, select all, start a clearance markdown.</span>
    </form>
    <?php endif ?>
    <div class="table-responsive"><table class="table table-bc table-stack mb-0">
        <thead><tr><?php if ($canManage): ?><th class="text-center" style="width:2.5rem"><input class="form-check-input" type="checkbox" data-bulk-all aria-label="Select all listings on this page"></th><?php endif ?><th>Product</th><th>Category</th><th class="text-end">On hand</th><th class="text-end">Reserved</th><th>Price</th><th>Age</th><th>Visibility</th><th>Status</th><th></th></tr></thead>
        <tbody><?php foreach ($products as $p): ?><tr>
            <?php if ($canManage): ?><td class="text-center"><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int) $p['id'] ?>" form="bulkForm" data-bulk-item aria-label="Select <?= esc($p['part_number'], 'attr') ?>"></td><?php endif ?>
            <td data-label="Product"><a class="fw-semibold" href="<?= site_url('supplier/products/' . $p['id'] . '/edit') ?>"><span class="part-no"><?= esc($p['part_number']) ?></span></a><div class="small"><?= esc($p['name']) ?></div><div class="small text-muted">SKU <?= esc($p['sku']) ?> · <?= esc($p['brand_name'] ?? 'Unbranded') ?></div></td>
            <td data-label="Category" class="small"><?= esc($p['category_name']) ?></td>
            <td data-label="On hand" class="text-end"><?= number_format((int) $p['stock_on_hand']) ?></td><td data-label="Reserved" class="text-end"><?= number_format((int) $p['stock_reserved']) ?></td>
            <td data-label="Price" class="small"><?= $p['price_visibility'] === 'on_request' ? 'On request' : ($p['sale_mode'] === 'lot' ? money($p['lot_price'], $p['currency']) . ' / lot' : money($p['unit_price'], $p['currency'], 2) . ' / pc') ?><?= $p['clearance_started_at'] ? '<br><span class="badge-soft warning">Clearance −' . rtrim(rtrim(number_format((float) $p['clearance_pct'], 2), '0'), '.') . '%</span>' : '' ?></td>
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
