<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if (can('services.manage')): ?><a href="<?= site_url('services/new') ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> New service</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<form class="filter-bar d-flex gap-2" method="get"><input type="search" name="q" value="<?= esc($q) ?>" class="form-control" placeholder="Search services" aria-label="Search services"><button class="btn btn-primary">Search</button></form>
<div class="alert alert-secondary small border"><i class="bi bi-info-circle"></i> Default SAC codes and rates are editable suggestions. A service marked <span class="badge badge-warning">Suggested</span> has not been confirmed by your accountant.</div>
<div class="table-card"><div class="table-responsive">
<table class="table table-hover align-middle small mb-0">
    <thead><tr><th>Code</th><th>Service</th><th>Category</th><th>SAC</th><th>Unit</th><th class="num">Default rate</th><th class="num">GST %</th><th>Review</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
        <tr class="<?= $s['is_active'] ? '' : 'text-muted' ?>">
            <td class="mono"><?= esc($s['code']) ?></td>
            <td><strong><?= esc($s['name']) ?></strong><?= $s['is_recurring_type'] ? ' <span class="badge badge-neutral">recurring</span>' : '' ?><?= $s['pos_rule'] === 'review_required' ? ' <span class="badge badge-warning" title="Special place-of-supply rule — invoices need tax review">POS review</span>' : '' ?><div class="text-muted"><?= esc(mb_strimwidth((string) $s['description'], 0, 90, '…')) ?></div></td>
            <td><?= esc((string) $s['category']) ?></td>
            <td class="mono"><?= esc($s['sac_code'] ?? '—') ?></td>
            <td><?= esc($s['unit']) ?></td>
            <td class="num"><?= inr($s['default_rate']) ?></td>
            <td class="num"><?= $s['tax_rate'] !== null ? esc(rtrim(rtrim($s['tax_rate'], '0'), '.')) : '—' ?></td>
            <td><?= status_badge($s['review_status']) ?></td>
            <td><?php if (can('services.manage')): ?><a href="<?= site_url('services/' . $s['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary">Edit</a><?php endif ?></td>
        </tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="9"><div class="empty-state"><i class="bi bi-grid"></i>No services.</div></td></tr><?php endif ?>
    </tbody>
</table>
</div></div>
<?= $this->endSection() ?>
