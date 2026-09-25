<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var array $b @var list<array> $mix @var list<array> $variants */ ?>
<div class="page-head">
    <div><div class="small"><a href="<?= site_url('admin/batches') ?>">Batches</a></div><h1 class="mono"><?= esc($b['batch_code']) ?></h1>
    <p><?= esc($b['warehouse']) ?> · made <?= esc(local_date($b['manufactured_on'])) ?> · <?= (int) $b['produced_quantity'] ?> of <?= (int) $b['planned_quantity'] ?> planned</p></div>
    <a class="btn btn-light" href="<?= site_url('admin/mattresses?batch=' . $b['id']) ?>">View units</a>
</div>
<div class="row g-3">
    <div class="col-xl-5">
        <div class="panel"><div class="panel-head"><h2>Details</h2></div><div class="panel-body"><dl class="dl-grid">
            <dt>Line supervisor</dt><dd><?= esc($b['line_supervisor'] ?? '—') ?></dd>
            <dt>Quality checked by</dt><dd><?= esc($b['quality_checked_by'] ?? '—') ?></dd>
            <dt>Created by</dt><dd><?= esc($b['created_by_name'] ?? '—') ?>, <?= esc(local_time($b['created_at'])) ?></dd>
            <dt>Notes</dt><dd><?= esc($b['notes'] ?? '—') ?></dd>
        </dl></div></div>
        <div class="panel"><div class="panel-head"><h2>Units in this batch</h2></div>
            <?php if ($mix === []): ?><div class="table-empty">None serialised yet.</div><?php else: ?>
            <table class="table"><tbody><?php foreach ($mix as $r): ?><tr><td><?= esc($r['name']) ?></td><td class="small"><?= esc($r['size_label']) ?></td><td class="num"><?= (int) $r['n'] ?></td></tr><?php endforeach ?></tbody></table>
            <?php endif ?>
        </div>
    </div>
    <?php if ($ctx->can('mattress:create')): ?>
    <div class="col-xl-7">
        <form class="panel" method="post" action="<?= site_url('admin/batches/' . $b['id'] . '/produce') ?>" data-confirm="Issue serial numbers for these units? Serials are permanent.">
            <div class="panel-head"><h2>Produce units</h2><span class="small text-muted">Up to 2,000 per run</span></div>
            <div class="panel-body">
                <?= csrf_field() ?>
                <p class="small text-muted">Each unit gets the next permanent serial number and a unique QR token. The whole run succeeds or none of it does.</p>
                <div class="table-responsive"><table class="table">
                    <thead><tr><th>Product</th><th>Size</th><th>SKU</th><th class="num" style="width:8rem">Quantity</th></tr></thead>
                    <tbody><?php foreach ($variants as $v): ?>
                        <tr><td><?= esc($v['product']) ?></td><td class="small"><?= esc($v['size_label']) ?></td><td class="mono small"><?= esc($v['sku']) ?></td>
                        <td><input class="form-control form-control-sm text-end" type="number" min="0" max="2000" name="qty[<?= $v['id'] ?>]" aria-label="Quantity of <?= esc($v['product'] . ' ' . $v['size_label'], 'attr') ?>"></td></tr>
                    <?php endforeach ?></tbody>
                </table></div>
                <button class="btn btn-primary" type="submit">Serialise units</button>
            </div>
        </form>
    </div>
    <?php endif ?>
</div>
<?= $this->endSection() ?>
