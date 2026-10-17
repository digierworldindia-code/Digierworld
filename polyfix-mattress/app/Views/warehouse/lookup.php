<?= $this->extend('layouts/floor') ?>

<?php
/**
 * One mattress: where it is, and everything that has happened to it.
 *
 * The question on the floor is nearly always "why is this one not where I
 * expected?", and the answer is in the events, so the whole history is shown
 * rather than a summary.
 *
 * @var string                    $code
 * @var array<string,mixed>|null  $unit
 * @var list<array<string,mixed>> $events
 */
?>

<?= $this->section('content') ?>

<div class="page-head">
    <h1>Find a mattress</h1>
    <p>Type a serial, or paste what a scanner read.</p>
</div>

<form class="panel p-3 mb-3" method="get" action="<?= site_url('warehouse/find') ?>">
    <label class="visually-hidden" for="code">Serial number</label>
    <div class="input-group input-group-lg">
        <input class="form-control mono" type="search" id="code" name="code" value="<?= esc($code, 'attr') ?>"
               maxlength="300" autocomplete="off" autocapitalize="characters" spellcheck="false"
               placeholder="<?= esc(brand('serialPrefix'), 'attr') ?>-<?= esc(local_time(utc_now(), 'Y'), 'attr') ?>-000123" autofocus>
        <button class="btn btn-primary" type="submit">Find</button>
    </div>
</form>

<?php if ($code !== '' && $unit === null): ?>
    <div class="panel p-4 text-center">
        <p class="mb-1 fw-semibold">Nothing carries that code.</p>
        <p class="mb-0 small text-muted">Check the label. A <?= esc(brand('shortName')) ?> serial reads
            <span class="mono"><?= esc(brand('serialPrefix')) ?>-YYYY-NNNNNN</span>; older labels read
            <span class="mono">CLF</span> and eight digits.</p>
    </div>
<?php elseif ($unit !== null): ?>
    <div class="panel mb-3">
        <div class="panel-head">
            <strong class="mono"><?= esc($unit['serial_number']) ?></strong>
            <?= pill($unit['current_status']) ?>
        </div>
        <div class="p-3">
            <dl class="row mb-0 small">
                <dt class="col-5">Model</dt><dd class="col-7"><?= esc($unit['product_name'] ?? '—') ?></dd>
                <dt class="col-5">Size</dt><dd class="col-7"><?= esc($unit['size_label'] ?? '—') ?></dd>
                <dt class="col-5">Batch</dt><dd class="col-7 mono"><?= esc($unit['batch_code'] ?? '—') ?></dd>
                <dt class="col-5">Made</dt><dd class="col-7"><?= esc(local_date($unit['manufactured_at'])) ?></dd>
            </dl>
        </div>
    </div>

    <h2 class="h2 mb-2">History</h2>
    <div class="panel">
        <ul class="list-unstyled mb-0">
            <?php foreach ($events as $event): ?>
                <li class="p-3 border-bottom">
                    <div class="d-flex justify-content-between gap-2">
                        <strong><?= esc(humanise($event['event_type'])) ?></strong>
                        <span class="small text-muted text-nowrap"><?= esc(local_time($event['occurred_at'])) ?></span>
                    </div>
                    <div class="small text-muted">
                        <?php if ($event['from_status'] !== null && $event['to_status'] !== null): ?>
                            <?= esc(humanise($event['from_status'])) ?> → <?= esc(humanise($event['to_status'])) ?>
                        <?php endif ?>
                        <?= $event['dealer'] !== null ? ' · ' . esc($event['dealer']) : '' ?>
                        <?= $event['actor'] !== null ? ' · ' . esc($event['actor']) : '' ?>
                    </div>
                </li>
            <?php endforeach ?>
        </ul>
    </div>
<?php endif ?>

<?= $this->endSection() ?>
