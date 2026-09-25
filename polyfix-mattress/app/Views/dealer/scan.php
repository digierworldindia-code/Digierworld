<?= $this->extend('layouts/portal') ?>
<?= $this->section('content') ?>
<?php /** @var string $serial @var array|null $unit @var string|null $error */ ?>
<h1 class="h4 mb-2">Scan a mattress</h1>
<p class="small text-muted">Point your phone's camera at the QR label — it opens this page for that unit. Or type the serial printed beside it.</p>

<form class="panel mb-3" method="get" action="<?= site_url('dealer/scan') ?>">
    <div class="panel-body">
        <label class="form-label" for="serial">Serial number</label>
        <div class="d-flex gap-2">
            <input class="form-control form-control-lg mono text-uppercase" id="serial" name="serial" inputmode="latin" autocapitalize="characters"
                   autocomplete="off" spellcheck="false" maxlength="20" value="<?= esc($serial, 'attr') ?>" placeholder="<?= esc(brand('serialPrefix'), 'attr') ?>26000001" autofocus>
            <button class="btn btn-primary btn-lg" type="submit">Check</button>
        </div>
    </div>
</form>

<?php if ($error !== null): ?><div class="alert alert-warning"><?= esc($error) ?></div><?php endif ?>

<?php if ($unit !== null): ?>
<div class="panel">
    <div class="panel-head"><h2 class="mono"><?= esc($unit['serial_number']) ?></h2><?= pill($unit['current_status']) ?></div>
    <div class="panel-body">
        <dl class="dl-grid">
            <dt>Product</dt><dd><?= esc($unit['product']) ?>, <?= esc($unit['size_label']) ?></dd>
            <dt>Comfort</dt><dd><?= esc($unit['comfort_level']) ?> · MRP <?= inr((float) $unit['mrp']) ?></dd>
            <dt>Made</dt><dd><?= esc(local_date($unit['manufactured_at'])) ?></dd>
            <?php if ($unit['received_at']): ?><dt>Received</dt><dd><?= esc(local_date($unit['received_at'])) ?></dd><?php endif ?>
            <?php if ($unit['sale']): ?>
            <dt>Sold</dt><dd><?= esc(local_date($unit['sale']['sold_at'])) ?> · invoice <?= esc($unit['sale']['invoice_number']) ?></dd>
            <dt>Customer</dt><dd><?= esc($unit['sale']['full_name']) ?><?= $unit['sale']['city'] ? ', ' . esc($unit['sale']['city']) : '' ?></dd>
            <?php endif ?>
            <?php if ($unit['warranty']): ?>
            <dt>Warranty</dt><dd><?= pill($unit['warranty']['status']) ?> to <?= esc(local_date($unit['warranty']['end_date'])) ?></dd>
            <?php endif ?>
            <?php if ($unit['is_replacement']): ?><dt>Note</dt><dd>This is a replacement unit.</dd><?php endif ?>
            <?php if ($unit['grade_note']): ?><dt>Condition</dt><dd><?= esc($unit['grade_note']) ?></dd><?php endif ?>
        </dl>

        <div class="d-grid gap-2 mt-3">
            <?php foreach ($unit['actions'] as $action): ?>
                <?php if ($action === 'SELL'): ?>
                <a class="btn btn-primary btn-lg" href="<?= site_url('dealer/sell') ?>?serial=<?= esc($unit['serial_number'], 'url') ?>">Record a sale</a>
                <?php elseif ($action === 'CLAIM'): ?>
                <a class="btn btn-primary btn-lg" href="<?= site_url('dealer/claims/new') ?>?serial=<?= esc($unit['serial_number'], 'url') ?>">Raise a warranty claim</a>
                <?php elseif ($action === 'RECEIVE'): ?>
                <a class="btn btn-primary btn-lg" href="<?= site_url('dealer/incoming') ?>">Confirm the consignment it came on</a>
                <?php endif ?>
            <?php endforeach ?>
        </div>

        <?php if ($unit['claims'] !== []): ?>
        <hr>
        <h3 class="h6">Claims on this unit</h3>
        <ul class="tap-list">
            <?php foreach ($unit['claims'] as $c): ?>
            <li><a href="<?= site_url('dealer/claims/' . $c['id']) ?>"><span><?= esc($c['claim_number']) ?>
                <span class="d-block small text-muted"><?= esc(local_date($c['submitted_at'])) ?></span></span><?= pill($c['status']) ?></a></li>
            <?php endforeach ?>
        </ul>
        <?php endif ?>
    </div>
</div>
<?php endif ?>
<?= $this->endSection() ?>
