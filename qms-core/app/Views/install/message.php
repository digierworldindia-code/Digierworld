<?= $this->extend('install/_layout') ?>
<?= $this->section('content') ?>
<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h4"><?= esc($title) ?></h2>
        <p class="mb-0"><?= esc($message) ?></p>
    </div>
</div>
<?= $this->endSection() ?>
