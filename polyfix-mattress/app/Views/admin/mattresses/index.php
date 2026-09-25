<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var array $list @var array $f @var list<string> $statuses @var list<array> $dealers @var list<array> $products */ ?>
<div class="page-head">
    <div><h1><?= $f['status'] === 'DEALER_RECEIVED' ? 'Dealer inventory' : 'Mattresses' ?></h1><p>Every serialised unit, newest first.</p></div>
    <?php if ($ctx->can('mattress:create')): ?><a class="btn btn-primary" href="<?= site_url('admin/batches') ?>"><i class="bi bi-plus-lg"></i> Produce units</a><?php endif ?>
</div>
<div class="panel">
    <form class="filters panel-body border-bottom" method="get">
        <div><label class="form-label" for="q">Serial</label><input class="form-control" id="q" name="q" value="<?= esc($f['q'] ?? '', 'attr') ?>" placeholder="<?= esc(brand('serialPrefix'), 'attr') ?>26…"></div>
        <div><label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status" data-autosubmit><option value="">Any status</option>
                <?php foreach ($statuses as $s): ?><option value="<?= $s ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= esc(humanise($s)) ?></option><?php endforeach ?></select></div>
        <div><label class="form-label" for="product">Product</label>
            <select class="form-select" id="product" name="product" data-autosubmit><option value="">Any product</option>
                <?php foreach ($products as $p): ?><option value="<?= $p['id'] ?>"<?= $f['product'] === $p['id'] ? ' selected' : '' ?>><?= esc($p['name']) ?></option><?php endforeach ?></select></div>
        <div><label class="form-label" for="dealer">Dealer</label>
            <select class="form-select" id="dealer" name="dealer" data-autosubmit><option value="">Any dealer</option>
                <?php foreach ($dealers as $d): ?><option value="<?= $d['id'] ?>"<?= $f['dealer'] === $d['id'] ? ' selected' : '' ?>><?= esc($d['business_name']) ?></option><?php endforeach ?></select></div>
        <?php if ($f['batch']): ?><input type="hidden" name="batch" value="<?= esc($f['batch'], 'attr') ?>"><?php endif ?>
        <div><button class="btn btn-light" type="submit">Filter</button> <?php if (array_filter($f)): ?><a class="btn btn-link" href="<?= site_url('admin/mattresses') ?>">Clear</a><?php endif ?></div>
    </form>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Serial</th><th>Product</th><th>Size</th><th>Batch</th><th>Status</th><th>Dealer</th><th>Made</th><th>Sold</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $r): ?>
        <tr>
            <td><a class="mono fw-semibold" href="<?= site_url('admin/mattresses/' . $r['id']) ?>"><?= esc($r['serial_number']) ?></a><?= $r['is_replacement'] ? ' <span class="pill pill-accent">Replacement</span>' : '' ?></td>
            <td><?= esc($r['product']) ?></td>
            <td class="small"><?= esc($r['size_label']) ?></td>
            <td class="mono small"><?= esc($r['batch_code']) ?></td>
            <td><?= pill($r['current_status']) ?></td>
            <td class="small"><?= esc($r['dealer'] ?? '—') ?></td>
            <td class="small text-nowrap"><?= esc(local_date($r['manufactured_at'])) ?></td>
            <td class="small text-nowrap"><?= esc(local_date($r['sold_at'])) ?></td>
        </tr>
        <?php endforeach ?>
        <?php if ($list['rows'] === []): ?><tr><td colspan="8" class="table-empty">No mattresses match.</td></tr><?php endif ?>
        </tbody>
    </table></div>
    <?= view('partials/pager', ['p' => $list]) ?>
</div>
<?= $this->endSection() ?>
