<?= $this->extend('layouts/floor') ?>

<?php
/**
 * A consignment that has already gone. Read only — once custody has passed
 * to the dealer, what happens next is a receipt or a return, not an edit.
 *
 * @var array<string,mixed>       $d
 * @var list<array<string,mixed>> $items
 */
?>

<?= $this->section('content') ?>

<div class="page-head">
    <h1 class="mono"><?= esc($d['dispatch_code']) ?></h1>
    <p><?= esc($d['dealer']) ?> · <?= count($items) ?> mattresses</p>
</div>

<div class="panel mb-3">
    <div class="panel-head">
        <strong>Status</strong>
        <?= pill($d['status']) ?>
    </div>
    <div class="p-3">
        <dl class="row mb-0 small">
            <dt class="col-5">Sent</dt><dd class="col-7"><?= esc(local_time($d['dispatched_at'])) ?></dd>
            <dt class="col-5">From</dt><dd class="col-7"><?= esc($d['warehouse']) ?></dd>
            <?php if ($d['transporter'] !== null): ?>
                <dt class="col-5">Transporter</dt><dd class="col-7"><?= esc($d['transporter']) ?></dd>
            <?php endif ?>
            <?php if ($d['lr_number'] !== null): ?>
                <dt class="col-5">LR number</dt><dd class="col-7 mono"><?= esc($d['lr_number']) ?></dd>
            <?php endif ?>
            <?php if ($d['vehicle_number'] !== null): ?>
                <dt class="col-5">Vehicle</dt><dd class="col-7 mono"><?= esc($d['vehicle_number']) ?></dd>
            <?php endif ?>
        </dl>
    </div>
</div>

<h2 class="h2 mb-2">What went</h2>
<div class="panel">
    <div class="table-responsive">
        <table class="table mb-0">
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <span class="mono fw-semibold"><?= esc($item['serial_number']) ?></span>
                        <span class="d-block small text-muted"><?= esc($item['product_name']) ?> · <?= esc($item['size_label']) ?></span>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
