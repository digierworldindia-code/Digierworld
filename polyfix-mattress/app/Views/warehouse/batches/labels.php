<?= $this->extend('layouts/floor') ?>

<?php
/**
 * A printable sheet of labels for a whole batch.
 *
 * This is what makes "no typing" true. The serials and QR codes already
 * exist — they were issued when the batch was opened — and this prints them
 * so they can be stuck on the mattresses as they come off the line. Each
 * label carries the four things the floor and the customer both need: the
 * name, the QR code, the serial as a barcode, and the model and size.
 *
 * Laid out in a grid that fits A4 at three across, with each label kept
 * whole across a page break.
 *
 * @var array<string,mixed>       $b
 * @var list<array<string,mixed>> $units
 */
?>

<?= $this->section('content') ?>

<div class="page-head no-print d-flex justify-content-between align-items-start gap-2">
    <div>
        <h1>Labels</h1>
        <p><?= count($units) ?> labels for batch <span class="mono"><?= esc($b['batch_code']) ?></span>.
            Print on plain A4 and apply as the mattresses come off the line.</p>
    </div>
    <button class="btn btn-primary btn-floor" type="button" data-print>
        <i class="bi bi-printer me-1" aria-hidden="true"></i>Print
    </button>
</div>

<div class="label-grid">
    <?php foreach ($units as $unit): ?>
        <div class="label-card">
            <div class="label-card__qr"><?= $unit['qr'] ?></div>
            <div class="label-card__text">
                <div class="label-card__brand"><?= brand_wordmark() ?></div>
                <div class="label-card__model"><?= esc($unit['product_name']) ?></div>
                <div class="label-card__size"><?= esc($unit['size_label']) ?></div>
                <div class="label-card__barcode"><?= $unit['barcode'] ?></div>
                <div class="label-card__serial mono"><?= esc($unit['serial_number']) ?></div>
                <div class="label-card__batch">Batch <?= esc($b['batch_code']) ?> · <?= esc(local_date($b['manufactured_on'])) ?></div>
            </div>
        </div>
    <?php endforeach ?>
</div>

<?= $this->endSection() ?>
