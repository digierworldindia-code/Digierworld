<?= $this->extend('layouts/portal') ?>
<?= $this->section('content') ?>
<?php /** @var array $counts @var list<array> $incoming @var list<array> $claims */ ?>
<h1 class="h4 mb-1">Hello, <?= esc(explode(' ', $ctx->user()['full_name'])[0]) ?></h1>
<p class="small text-muted mb-3"><?= esc($ctx->user()['dealer_name']) ?></p>

<?php if ((int) $counts['waiting_on_you'] > 0): ?>
<div class="alert alert-warning py-2"><?= (int) $counts['waiting_on_you'] ?> claim(s) are waiting for information from you. <a href="<?= site_url('dealer/claims') ?>">Open them</a>.</div>
<?php endif ?>

<div class="row g-2 mb-3">
    <div class="col-6"><a class="stat" href="<?= site_url('dealer/inventory') ?>"><div class="stat__label">In stock</div><div class="stat__value"><?= (int) $counts['in_stock'] ?></div></a></div>
    <div class="col-6"><a class="stat" href="<?= site_url('dealer/incoming') ?>"><div class="stat__label">Arriving</div><div class="stat__value"><?= (int) $counts['incoming'] ?></div></a></div>
    <div class="col-6"><a class="stat" href="<?= site_url('dealer/sales') ?>"><div class="stat__label">Sold this month</div><div class="stat__value"><?= (int) $counts['sales_month'] ?></div></a></div>
    <div class="col-6"><a class="stat" href="<?= site_url('dealer/claims') ?>"><div class="stat__label">Open claims</div><div class="stat__value"><?= (int) $counts['open_claims'] ?></div></a></div>
</div>

<div class="row g-2 mb-3">
    <div class="col-6"><a class="big-action" href="<?= site_url('dealer/scan') ?>"><i class="bi bi-qr-code-scan" aria-hidden="true"></i><span><strong>Scan</strong><span>Check any mattress</span></span></a></div>
    <div class="col-6"><a class="big-action" href="<?= site_url('dealer/sell') ?>"><i class="bi bi-receipt" aria-hidden="true"></i><span><strong>Record a sale</strong><span>Starts the warranty</span></span></a></div>
</div>

<?php if ($incoming !== []): ?>
<div class="panel mb-3">
    <div class="panel-head"><h2>Arriving</h2><a class="small" href="<?= site_url('dealer/incoming') ?>">All</a></div>
    <ul class="tap-list">
        <?php foreach ($incoming as $d): ?>
        <li><a href="<?= site_url('dealer/incoming/' . $d['id']) ?>">
            <span><strong class="mono"><?= esc($d['dispatch_code']) ?></strong>
                <span class="d-block small text-muted"><?= (int) $d['units'] ?> unit(s) · sent <?= esc(local_date($d['dispatched_at'])) ?></span></span>
            <span><?= pill($d['status']) ?> <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i></span>
        </a></li>
        <?php endforeach ?>
    </ul>
</div>
<?php endif ?>

<?php if ($claims !== []): ?>
<div class="panel">
    <div class="panel-head"><h2>Open claims</h2><a class="small" href="<?= site_url('dealer/claims') ?>">All</a></div>
    <ul class="tap-list">
        <?php foreach ($claims as $c): ?>
        <li><a href="<?= site_url('dealer/claims/' . $c['id']) ?>">
            <span><strong><?= esc($c['claim_number']) ?></strong>
                <span class="d-block small text-muted"><?= esc(mb_strimwidth($c['reported_issue'], 0, 48, '…')) ?></span></span>
            <span><?= pill($c['status']) ?></span>
        </a></li>
        <?php endforeach ?>
    </ul>
</div>
<?php endif ?>
<?= $this->endSection() ?>
