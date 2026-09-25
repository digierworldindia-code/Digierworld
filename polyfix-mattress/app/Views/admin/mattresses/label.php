<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var array $m @var string $qr @var string $barcode @var string $url */ ?>
<div class="page-head">
    <div><div class="small"><a href="<?= site_url('admin/mattresses/' . $m['id']) ?>"><?= esc($m['serial_number']) ?></a></div><h1>Label</h1>
    <p>The QR code opens the public verification page. It carries an opaque token, never the serial.</p></div>
    <button class="btn btn-primary" type="button" data-print><i class="bi bi-printer"></i> Print</button>
</div>
<div class="label-sheet">
    <div style="width:150px"><?= $qr ?></div>
    <div>
        <div class="fw-bold" style="letter-spacing:.14em"><?= brand_wordmark() ?></div>
        <div class="small"><?= esc($m['product']) ?> · <?= esc($m['size_label']) ?></div>
        <div class="small text-muted">Batch <?= esc($m['batch_code']) ?> · <?= esc(local_date($m['manufactured_at'])) ?></div>
        <div class="mt-2" style="max-width:260px"><?= $barcode ?></div>
        <div class="mono fw-semibold mt-1"><?= esc($m['serial_number']) ?></div>
        <div class="small text-muted mt-1">Verify: <?= esc(preg_replace('~^https?://~', '', site_url('warranty'))) ?></div>
    </div>
</div>
<p class="small text-muted mt-3 no-print">Encoded URL: <code class="text-break"><?= esc($url) ?></code></p>
<?= $this->endSection() ?>
