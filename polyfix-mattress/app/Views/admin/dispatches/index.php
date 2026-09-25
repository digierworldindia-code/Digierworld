<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var array $list @var array $f @var list<string> $statuses */ ?>
<div class="page-head">
    <div><h1>Dispatches</h1><p>Consignments from the warehouse to dealers.</p></div>
    <?php if ($ctx->can('dispatch:create')): ?><a class="btn btn-primary" href="<?= site_url('admin/dispatches/new') ?>"><i class="bi bi-plus-lg"></i> New dispatch</a><?php endif ?>
</div>
<div class="panel">
    <form class="filters panel-body border-bottom" method="get">
        <div><label class="form-label" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= esc($f['q'] ?? '', 'attr') ?>" placeholder="Code, dealer or invoice"></div>
        <div><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status" data-autosubmit><option value="">Any</option>
            <?php foreach ($statuses as $s): ?><option value="<?= $s ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= $s === 'DRAFT' ? 'Ready' : esc(humanise($s)) ?></option><?php endforeach ?></select></div>
        <div><button class="btn btn-light" type="submit">Filter</button></div>
    </form>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Dispatch</th><th>Dealer</th><th>From</th><th class="num">Units</th><th>Status</th><th>Invoice</th><th>Sent</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $r): ?>
        <tr><td><a class="mono fw-semibold" href="<?= site_url('admin/dispatches/' . $r['id']) ?>"><?= esc($r['dispatch_code']) ?></a></td>
            <td><?= esc($r['dealer']) ?> <span class="small text-muted"><?= esc($r['city']) ?></span></td>
            <td class="small"><?= esc($r['warehouse']) ?></td><td class="num"><?= (int) $r['units'] ?></td>
            <td><?= pill($r['status'], $r['status'] === 'DRAFT' ? 'Ready' : null) ?></td>
            <td class="small"><?= esc($r['invoice_number'] ?? '—') ?></td>
            <td class="small text-nowrap"><?= esc(local_date($r['dispatched_at'])) ?></td></tr>
        <?php endforeach ?>
        <?php if ($list['rows'] === []): ?><tr><td colspan="7" class="table-empty">No dispatches match.</td></tr><?php endif ?>
        </tbody>
    </table></div>
    <?= view('partials/pager', ['p' => $list]) ?>
</div>
<?= $this->endSection() ?>
