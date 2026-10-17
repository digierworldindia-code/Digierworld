<?= $this->extend('layouts/floor') ?>

<?php
/**
 * The scanning screen. Camera, count, last result — nothing else.
 *
 * @var array<string,mixed> $b
 * @var array<string,mixed> $progress
 */
?>

<?= $this->section('content') ?>

<div class="page-head">
    <h1 class="mono"><?= esc($b['batch_code']) ?></h1>
    <p>Scan each mattress once. The camera stays open.</p>
</div>

<?= view('partials/scanner', [
    'endpoint'  => site_url('warehouse/batches/' . $b['id'] . '/scan'),
    'counter'   => $progress['counter']['label'],
    'hint'      => 'Hold the QR code on the mattress label inside the frame.',
    'autostart' => true,
]) ?>

<p class="small text-muted mt-3 mb-0">
    Done for now? <a href="<?= site_url('warehouse/batches/' . $b['id']) ?>">Back to the batch</a> —
    the count is saved as you go.
</p>

<?= $this->endSection() ?>
