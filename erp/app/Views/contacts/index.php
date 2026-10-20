<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if (can('contacts.import')): ?><a href="<?= site_url('contacts/import') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-upload"></i> Import</a><?php endif ?>
<?php if (can('contacts.export')): ?>
<div class="btn-group btn-group-sm"><a class="btn btn-outline-secondary" href="<?= site_url('contacts/export?' . http_build_query($f + ['format' => 'xlsx'])) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a><a class="btn btn-outline-secondary" href="<?= site_url('contacts/export?' . http_build_query($f + ['format' => 'csv'])) ?>">CSV</a></div>
<?php endif ?>
<?php if (can('contacts.create')): ?><a href="<?= site_url('contacts/new') ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> New contact</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<form class="filter-bar row g-2" method="get">
    <div class="col-md-4"><input type="search" name="q" value="<?= esc($f['q']) ?>" class="form-control" placeholder="Name, code, GSTIN, phone, email" aria-label="Search contacts"></div>
    <div class="col-6 col-md-2"><select name="type" class="form-select" data-autosubmit aria-label="Type"><option value="">All types</option>
        <?php foreach (['customer' => 'Customers', 'vendor' => 'Vendors', 'prospect' => 'Prospects'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['type'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-2"><select name="state" class="form-select" data-autosubmit aria-label="State"><option value="">All states</option>
        <?php foreach ($states as $s): ?><option value="<?= $s['code'] ?>" <?= $f['state'] === $s['code'] ? 'selected' : '' ?>><?= esc($s['name']) ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-2"><select name="status" class="form-select" data-autosubmit aria-label="Status"><option value="active" <?= $f['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $f['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option><option value="all" <?= $f['status'] === 'all' ? 'selected' : '' ?>>All</option></select></div>
    <div class="col-6 col-md-2 d-flex gap-2"><button class="btn btn-primary flex-grow-1">Filter</button>
        <div class="dropdown"><button class="btn btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-label="Choose columns"><i class="bi bi-layout-three-columns"></i></button><div class="dropdown-menu dropdown-menu-end" data-col-menu="contacts"></div></div></div>
</form>
<div class="table-card"><div class="table-responsive">
<table class="table table-hover align-middle" data-columns-key="contacts">
    <thead><tr><th data-col="code">Code</th><th>Name</th><th data-col="type">Type</th><th data-col="phone">Phone</th><th data-col="gstin">GSTIN</th><th data-col="state">State</th><th data-col="city">City</th><?php if (can('contacts.finance_view')): ?><th class="num" data-col="due">Outstanding</th><?php endif ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td class="small mono"><?= esc($r['contact_code']) ?></td>
            <td><a href="<?= site_url('contacts/' . $r['id']) ?>" class="fw-semibold"><?= esc($r['name']) ?></a><?= $r['dnd'] ? ' <span class="badge badge-neutral" title="Do not call">DNC</span>' : '' ?><div class="small text-muted"><?= esc($r['contact_person'] ?? '') ?></div></td>
            <td class="small"><?= $r['is_customer'] ? '<span class="badge badge-neutral">Customer</span> ' : '' ?><?= $r['is_vendor'] ? '<span class="badge badge-neutral">Vendor</span> ' : '' ?><?= $r['is_prospect'] ? '<span class="badge badge-neutral">Prospect</span>' : '' ?></td>
            <td class="small"><?= esc($r['phone'] ?? '') ?></td>
            <td class="small mono"><?= esc($r['gstin'] ?? '—') ?></td>
            <td class="small"><?= esc($r['state_name'] ?? '') ?></td>
            <td class="small"><?= esc($r['city'] ?? '') ?></td>
            <?php if (can('contacts.finance_view')): ?><td class="num small"><?= isset($outstanding[(int) $r['id']]) ? inr($outstanding[(int) $r['id']]) : '—' ?></td><?php endif ?>
        </tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="8"><div class="empty-state"><i class="bi bi-person-vcard"></i>No contacts found. <?php if (can('contacts.create')): ?><a href="<?= site_url('contacts/new') ?>">Add your first client</a> or <a href="<?= site_url('contacts/import') ?>">import a sheet</a>.<?php endif ?></div></td></tr><?php endif ?>
    </tbody>
</table>
</div></div>
<?= view('partials/pager', ['total' => $total, 'page' => $page, 'perPage' => $perPage]) ?>
<?= $this->endSection() ?>
