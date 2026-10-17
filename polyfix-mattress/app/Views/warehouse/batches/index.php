<?= $this->extend('layouts/floor') ?>

<?php
/**
 * Batches, newest first, each showing how far it has been counted.
 *
 * The badge is the only thing most people read here: amber means units are
 * still waiting to be scanned, green means the run is accounted for.
 *
 * @var list<array<string,mixed>> $batches
 */
?>

<?= $this->section('content') ?>

<div class="page-head d-flex justify-content-between align-items-start gap-2">
    <div><h1>Batches</h1><p>Open one to scan mattresses in.</p></div>
    <a class="btn btn-primary btn-floor" href="<?= site_url('warehouse/batches/new') ?>">
        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New
    </a>
</div>

<?php if ($batches === []): ?>
    <div class="panel p-4 text-center text-muted">
        <p class="mb-3">No batches yet.</p>
        <a class="btn btn-primary btn-floor" href="<?= site_url('warehouse/batches/new') ?>">Open the first batch</a>
    </div>
<?php else: ?>
    <div class="floor-menu">
        <?php foreach ($batches as $batch): ?>
            <?php
            $produced = (int) $batch['produced'];
            $scanned  = (int) $batch['scanned'];
            $done     = $produced > 0 && $scanned >= $produced;
            ?>
            <a class="floor-action <?= $done ? 'floor-action--muted' : '' ?>" href="<?= site_url('warehouse/batches/' . $batch['id']) ?>">
                <i class="bi <?= $done ? 'bi-check2-circle' : 'bi-qr-code-scan' ?>" aria-hidden="true"></i>
                <span>
                    <strong class="mono"><?= esc($batch['batch_code']) ?></strong>
                    <span><?= esc(local_date($batch['manufactured_on'])) ?> · <?= esc($batch['warehouse']) ?></span>
                </span>
                <span class="count text-nowrap <?= $done ? 'text-muted' : '' ?>"><?= $scanned ?>/<?= $produced ?></span>
            </a>
        <?php endforeach ?>
    </div>
<?php endif ?>

<?= $this->endSection() ?>
