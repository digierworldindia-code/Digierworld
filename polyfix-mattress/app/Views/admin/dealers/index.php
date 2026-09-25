<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var array $list @var array $f @var list<string> $statuses */ ?>
<div class="page-head">
    <div><h1>Dealers</h1><p>Appointed dealerships, their stock and their record.</p></div>
    <?php if ($ctx->can('dealer:write')): ?><a class="btn btn-primary" href="<?= site_url('admin/dealers/new') ?>"><i class="bi bi-plus-lg"></i> New dealer</a><?php endif ?>
</div>
<div class="panel">
    <form class="filters panel-body border-bottom" method="get">
        <div><label class="form-label" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= esc($f['q'] ?? '', 'attr') ?>" placeholder="Name, code, city or phone"></div>
        <div><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status" data-autosubmit><option value="">Any</option>
            <?php foreach ($statuses as $s): ?><option value="<?= $s ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= esc(humanise($s)) ?></option><?php endforeach ?></select></div>
        <div><button class="btn btn-light" type="submit">Filter</button></div>
    </form>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Dealer</th><th>Code</th><th>City</th><th>Phone</th><th>Status</th><th class="num">Stock</th><th class="num">Sales</th><th class="num">Claims</th><th>Listed</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $d): ?>
        <tr><td><a class="fw-semibold" href="<?= site_url('admin/dealers/' . $d['id']) ?>"><?= esc($d['business_name']) ?></a><div class="small text-muted"><?= esc($d['owner_name']) ?></div></td>
            <td class="mono small"><?= esc($d['code']) ?></td><td class="small"><?= esc($d['city']) ?>, <?= esc($d['state']) ?></td>
            <td class="small"><?= esc($d['phone']) ?></td><td><?= pill($d['status']) ?></td>
            <td class="num"><?= (int) $d['stock'] ?></td><td class="num"><?= (int) $d['sales'] ?></td><td class="num"><?= (int) $d['claims'] ?></td>
            <td class="small"><?= $d['public_listed'] ? 'Yes' : 'No' ?><?= $d['is_showroom'] ? ' · Showroom' : '' ?></td></tr>
        <?php endforeach ?>
        <?php if ($list['rows'] === []): ?><tr><td colspan="9" class="table-empty">No dealers match.</td></tr><?php endif ?>
        </tbody>
    </table></div>
    <?= view('partials/pager', ['p' => $list]) ?>
</div>
<?= $this->endSection() ?>
