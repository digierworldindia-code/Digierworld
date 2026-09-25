<?= $this->extend('layouts/portal') ?>
<?= $this->section('content') ?>
<?php /** @var array $d @var list<array> $items */
$pending = array_values(array_filter($items, static fn ($i) => $i['received_condition'] === null && $i['current_status'] === 'DISPATCHED'));
?>
<div class="small"><a href="<?= site_url('dealer/incoming') ?>">Incoming</a></div>
<h1 class="h4 mb-1 mono"><?= esc($d['dispatch_code']) ?> <?= pill($d['status']) ?></h1>
<p class="small text-muted"><?= count($items) ?> unit(s) · sent <?= esc(local_date($d['dispatched_at'])) ?><?= $d['invoice_number'] ? ' · invoice ' . esc($d['invoice_number']) : '' ?>
<?= $d['lr_number'] ? ' · LR ' . esc($d['lr_number']) : '' ?></p>

<?php if ($pending === []): ?>
<div class="alert alert-secondary">Every unit on this consignment has been accounted for.</div>
<div class="panel"><ul class="tap-list">
    <?php foreach ($items as $i): ?>
    <li><div class="row-item"><span class="mono"><?= esc($i['serial_number']) ?><span class="d-block small text-muted"><?= esc($i['product']) ?> · <?= esc($i['size_label']) ?></span></span>
        <?= $i['received_condition'] ? pill($i['received_condition']) : pill($i['current_status']) ?></div></li>
    <?php endforeach ?>
</ul></div>
<?php else: ?>
<form method="post" action="<?= site_url('dealer/incoming/' . $d['id'] . '/receive') ?>" data-confirm="Confirm this consignment as marked?">
    <?= csrf_field() ?>
    <div class="panel mb-3">
        <div class="panel-head"><h2>Check each unit</h2><span class="small text-muted"><?= count($pending) ?> to confirm</span></div>
        <ul class="tap-list">
        <?php foreach ($items as $i): ?>
            <?php $done = $i['received_condition'] !== null || $i['current_status'] !== 'DISPATCHED'; ?>
            <li><div class="row-item flex-wrap">
                <span class="mono"><?= esc($i['serial_number']) ?>
                    <span class="d-block small text-muted"><?= esc($i['product']) ?> · <?= esc($i['size_label']) ?></span></span>
                <?php if ($done): ?>
                    <?= $i['received_condition'] ? pill($i['received_condition']) : pill($i['current_status']) ?>
                <?php else: ?>
                <span class="btn-group btn-group-sm" role="group" aria-label="Condition of <?= esc($i['serial_number'], 'attr') ?>">
                    <input class="btn-check" type="radio" name="condition[<?= esc($i['serial_number'], 'attr') ?>]" id="ok-<?= esc($i['serial_number'], 'attr') ?>" value="OK" checked>
                    <label class="btn btn-outline-primary" for="ok-<?= esc($i['serial_number'], 'attr') ?>">OK</label>
                    <input class="btn-check" type="radio" name="condition[<?= esc($i['serial_number'], 'attr') ?>]" id="dmg-<?= esc($i['serial_number'], 'attr') ?>" value="DAMAGED">
                    <label class="btn btn-outline-primary" for="dmg-<?= esc($i['serial_number'], 'attr') ?>">Damaged</label>
                    <input class="btn-check" type="radio" name="condition[<?= esc($i['serial_number'], 'attr') ?>]" id="mis-<?= esc($i['serial_number'], 'attr') ?>" value="MISSING">
                    <label class="btn btn-outline-primary" for="mis-<?= esc($i['serial_number'], 'attr') ?>">Not here</label>
                </span>
                <input class="form-control form-control-sm mt-2" name="note[<?= esc($i['serial_number'], 'attr') ?>]" maxlength="300" placeholder="Note (only if damaged or missing)" aria-label="Note for <?= esc($i['serial_number'], 'attr') ?>">
                <?php endif ?>
            </div></li>
        <?php endforeach ?>
        </ul>
        <div class="panel-body">
            <label class="form-label" for="remarks">Anything about the delivery as a whole</label>
            <input class="form-control" id="remarks" name="remarks" maxlength="500">
        </div>
    </div>
    <button class="btn btn-primary btn-lg w-100" type="submit">Confirm consignment</button>
    <p class="small text-muted mt-2">Damaged and missing units are reported to <?= esc(brand('shortName')) ?> straight away. A unit marked OK enters your stock and can be sold.</p>
</form>
<?php endif ?>
<?= $this->endSection() ?>
