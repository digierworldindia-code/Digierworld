<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var array $list @var array $totals @var list<array> $dealers @var array $f */ ?>
<div class="page-head">
    <div><h1>Sales</h1><p><?= number_format((int) $totals['n']) ?> sale(s), <?= inr((float) $totals['value']) ?> at retail.</p></div>
    <?php if ($ctx->can('report:export')): ?><a class="btn btn-light" href="<?= site_url('admin/reports/sales') ?>">Sales report</a><?php endif ?>
</div>
<div class="panel">
    <form class="filters panel-body border-bottom" method="get">
        <div><label class="form-label" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= esc($f['q'] ?? '', 'attr') ?>" placeholder="Serial, invoice or customer"></div>
        <div><label class="form-label" for="dealer">Dealer</label><select class="form-select" id="dealer" name="dealer" data-autosubmit><option value="">Any</option>
            <?php foreach ($dealers as $d): ?><option value="<?= $d['id'] ?>"<?= $f['dealer'] === $d['id'] ? ' selected' : '' ?>><?= esc($d['business_name']) ?></option><?php endforeach ?></select></div>
        <div><label class="form-label" for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= esc($f['from'] ?? '', 'attr') ?>"></div>
        <div><label class="form-label" for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= esc($f['to'] ?? '', 'attr') ?>"></div>
        <div><button class="btn btn-light" type="submit">Filter</button></div>
    </form>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Sold</th><th>Invoice</th><th>Serial</th><th>Product</th><th>Dealer</th><th>Customer</th><th class="num">Price</th><th>Warranty</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $s): ?>
        <tr><td class="small text-nowrap"><?= esc(local_date($s['sold_at'])) ?></td><td class="small"><?= esc($s['invoice_number']) ?></td>
            <td><a class="mono small" href="<?= site_url('admin/mattresses/' . $s['mattress_id']) ?>"><?= esc($s['serial_number']) ?></a></td>
            <td class="small"><?= esc($s['product']) ?><div class="text-muted"><?= esc($s['size_label']) ?></div></td>
            <td class="small"><?= esc($s['dealer']) ?></td><td class="small"><?= esc($s['customer']) ?></td>
            <td class="num"><?= inr((float) $s['sale_price']) ?></td>
            <td><?= $s['warranty_id'] && $ctx->can('warranty:read') ? '<a href="' . site_url('admin/warranties/' . $s['warranty_id']) . '">' . pill($s['warranty_status']) . '</a>' : ($s['warranty_status'] ? pill($s['warranty_status']) : '—') ?></td></tr>
        <?php endforeach ?>
        <?php if ($list['rows'] === []): ?><tr><td colspan="8" class="table-empty">No sales match.</td></tr><?php endif ?>
        </tbody>
    </table></div>
    <?= view('partials/pager', ['p' => $list]) ?>
</div>
<?= $this->endSection() ?>
