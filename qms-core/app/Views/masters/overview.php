<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="qms-page-head">
    <div><h1>Master data</h1><p class="qms-sub">Reference lists used by templates and inspections. Codes never change; unused rows are retired, not deleted.</p></div>
</div>
<div class="qms-tiles">
    <?php foreach ($definitions as $slug => $def): ?>
        <a class="qms-tile" href="<?= site_url('masters/' . $slug) ?>">
            <?= qms_icon($def['icon']) ?>
            <strong><?= esc($def['title']) ?></strong>
            <span><?= number_format($counts[$slug] ?? 0) ?> active</span>
        </a>
    <?php endforeach ?>
    <a class="qms-tile" href="<?= site_url('gauges') ?>"><?= qms_icon('bi-rulers') ?><strong>Gauges</strong><span>Instruments and calibration</span></a>
</div>
<?= $this->endSection() ?>
