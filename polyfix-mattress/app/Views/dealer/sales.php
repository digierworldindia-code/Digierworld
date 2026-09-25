<?= $this->extend('layouts/portal') ?>
<?= $this->section('content') ?>
<?php /** @var array $list */ ?>
<h1 class="h4 mb-2">My sales</h1>
<p class="small text-muted"><?= number_format($list['total']) ?> recorded.</p>
<div class="panel">
    <?php if ($list['rows'] === []): ?><div class="table-empty">No sales recorded yet.</div><?php endif ?>
    <ul class="tap-list">
    <?php foreach ($list['rows'] as $s): ?>
        <li><a href="<?= site_url('dealer/scan') ?>?serial=<?= esc($s['serial_number'], 'url') ?>">
            <span><strong><?= esc($s['invoice_number']) ?></strong>
                <span class="d-block small text-muted mono"><?= esc($s['serial_number']) ?> · <?= esc($s['product']) ?></span>
                <span class="d-block small text-muted"><?= esc($s['customer']) ?> · <?= esc(local_date($s['sold_at'])) ?></span></span>
            <span class="text-end"><span class="d-block"><?= inr((float) $s['sale_price']) ?></span>
                <?= $s['warranty_status'] ? pill($s['warranty_status']) : '' ?></span>
        </a></li>
    <?php endforeach ?>
    </ul>
    <?= view('partials/pager', ['p' => $list]) ?>
</div>
<?= $this->endSection() ?>
