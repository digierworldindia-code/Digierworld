<?= $this->extend('layouts/portal') ?>
<?= $this->section('content') ?>
<?php /** @var array $list @var list<array> $summary @var string $q */ ?>
<h1 class="h4 mb-2">Stock</h1>
<p class="small text-muted"><?= number_format($list['total']) ?> unit(s) received and unsold.</p>

<?php if ($summary !== []): ?>
<div class="panel mb-3">
    <table class="table mb-0"><tbody>
    <?php foreach ($summary as $s): ?>
        <tr><td><?= esc($s['product']) ?><div class="small text-muted"><?= esc($s['size_label']) ?></div></td><td class="num"><?= (int) $s['units'] ?></td></tr>
    <?php endforeach ?>
    </tbody></table>
</div>
<?php endif ?>

<form class="mb-3" method="get" action="<?= site_url('dealer/inventory') ?>">
    <div class="d-flex gap-2"><input class="form-control" name="q" value="<?= esc($q, 'attr') ?>" placeholder="Find a serial" inputmode="latin" autocapitalize="characters">
    <button class="btn btn-light" type="submit">Find</button></div>
</form>

<div class="panel">
    <?php if ($list['rows'] === []): ?><div class="table-empty">Nothing in stock.</div><?php endif ?>
    <ul class="tap-list">
    <?php foreach ($list['rows'] as $m): ?>
        <li><a href="<?= site_url('dealer/scan') ?>?serial=<?= esc($m['serial_number'], 'url') ?>">
            <span><strong class="mono"><?= esc($m['serial_number']) ?></strong>
            <span class="d-block small text-muted"><?= esc($m['product']) ?> · <?= esc($m['size_label']) ?></span></span>
            <span class="text-end"><span class="small d-block"><?= inr((float) $m['mrp']) ?></span>
            <span class="small text-muted">in since <?= esc(local_date($m['received_at'])) ?></span></span>
        </a></li>
    <?php endforeach ?>
    </ul>
    <?= view('partials/pager', ['p' => $list]) ?>
</div>
<?= $this->endSection() ?>
