<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="row g-3">
<?php foreach ($lists as $key => $d): ?>
    <div class="col-md-6 col-xl-4"><a class="card h-100 text-decoration-none text-reset" href="<?= site_url('settings/masters/' . $key) ?>"><div class="card-body">
        <div class="fw-semibold"><?= esc($d['label']) ?></div><div class="small text-muted"><?= count($d['fields']) ?> fields · edit inline</div>
    </div></a></div>
<?php endforeach ?>
<?php if ($lists === []): ?><div class="col-12"><div class="empty-state"><i class="bi bi-list-ul"></i>No master lists available for your role.</div></div><?php endif ?>
</div>
<?= $this->endSection() ?>
