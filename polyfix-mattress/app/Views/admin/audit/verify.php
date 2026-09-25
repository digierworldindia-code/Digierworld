<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var array{rows:int, problems:list<string>} $result */ ?>
<div class="page-head"><div><div class="small"><a href="<?= site_url('admin/audit') ?>">Audit trail</a></div><h1>Audit chain check</h1>
<p>Each entry carries the hash of the one before it. Re-walking the chain shows whether any row was altered outside the application.</p></div></div>
<div class="panel">
    <div class="panel-body">
        <?php if ($result['problems'] === []): ?>
        <p class="fs-5 mb-1"><span class="pill pill-positive">Intact</span></p>
        <p class="mb-0"><?= number_format($result['rows']) ?> entries checked; every hash matches its contents and the entry before it.</p>
        <?php else: ?>
        <p class="fs-5 mb-1"><span class="pill pill-critical">Broken</span></p>
        <p><?= number_format($result['rows']) ?> entries checked; <?= count($result['problems']) ?> problem(s) found. Treat this as evidence that the database was changed outside the application, and investigate before trusting the affected records.</p>
        <ul class="small"><?php foreach (array_slice($result['problems'], 0, 100) as $p): ?><li><?= esc($p) ?></li><?php endforeach ?></ul>
        <?php endif ?>
        <p class="small text-muted mt-3 mb-0">This check itself is recorded in the trail.</p>
    </div>
</div>
<?= $this->endSection() ?>
