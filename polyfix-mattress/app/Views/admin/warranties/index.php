<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var array $list @var list<array> $dealers @var array $f */ ?>
<div class="page-head"><div><h1>Warranties</h1><p>One per sold mattress. Expiry is computed from the sale date.</p></div>
    <a class="btn btn-light" href="<?= site_url('admin/warranties?expiring=1') ?>">Expiring in 90 days</a></div>
<div class="panel">
    <form class="filters panel-body border-bottom" method="get">
        <div><label class="form-label" for="q">Serial</label><input class="form-control" id="q" name="q" value="<?= esc($f['q'] ?? '', 'attr') ?>"></div>
        <div><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status" data-autosubmit><option value="">Any</option>
            <?php foreach (['ACTIVE', 'EXPIRED', 'VOID', 'SUPERSEDED'] as $s): ?><option value="<?= $s ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= esc(humanise($s)) ?></option><?php endforeach ?></select></div>
        <div><label class="form-label" for="dealer">Dealer</label><select class="form-select" id="dealer" name="dealer" data-autosubmit><option value="">Any</option>
            <?php foreach ($dealers as $d): ?><option value="<?= $d['id'] ?>"<?= $f['dealer'] === $d['id'] ? ' selected' : '' ?>><?= esc($d['business_name']) ?></option><?php endforeach ?></select></div>
        <?php if ($f['expiring']): ?><input type="hidden" name="expiring" value="1"><?php endif ?>
        <div><button class="btn btn-light" type="submit">Filter</button></div>
    </form>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Serial</th><th>Product</th><th>Dealer</th><th>Customer</th><th>Starts</th><th>Ends</th><th>Term</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $w): ?>
        <tr><td><a class="mono small fw-semibold" href="<?= site_url('admin/warranties/' . $w['id']) ?>"><?= esc($w['serial_number']) ?></a></td>
            <td class="small"><?= esc($w['product']) ?></td><td class="small"><?= esc($w['dealer']) ?></td><td class="small"><?= esc($w['customer'] ?? '—') ?></td>
            <td class="small text-nowrap"><?= esc(local_date($w['start_date'])) ?></td><td class="small text-nowrap"><?= esc(local_date($w['end_date'])) ?></td>
            <td class="small"><?= (int) $w['years'] ?> yrs</td><td><?= pill($w['status']) ?></td></tr>
        <?php endforeach ?>
        <?php if ($list['rows'] === []): ?><tr><td colspan="8" class="table-empty">No warranties match.</td></tr><?php endif ?>
        </tbody>
    </table></div>
    <?= view('partials/pager', ['p' => $list]) ?>
</div>
<?= $this->endSection() ?>
