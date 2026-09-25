<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var array $catalogue */ ?>
<div class="page-head"><div><h1>Reports</h1><p>Each one can be filtered on screen and downloaded as CSV or Excel.</p></div></div>
<div class="row g-3">
    <?php foreach ($catalogue as $key => $r): ?>
    <div class="col-md-6 col-xl-4">
        <a class="stat h-100 d-block" href="<?= site_url('admin/reports/' . $key) ?>">
            <div class="stat__label"><?= esc($r['title']) ?></div>
            <p class="small text-muted mt-2 mb-0"><?= esc($r['description']) ?></p>
        </a>
    </div>
    <?php endforeach ?>
</div>
<?= $this->endSection() ?>
