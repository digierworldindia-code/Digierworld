<?= $this->extend('layouts/portal') ?>
<?= $this->section('content') ?>
<?php /** @var array $list */ ?>
<div class="d-flex justify-content-between align-items-center mb-2">
    <h1 class="h4 mb-0">Claims</h1>
    <a class="btn btn-primary btn-sm" href="<?= site_url('dealer/claims/new') ?>"><i class="bi bi-plus-lg"></i> Raise a claim</a>
</div>
<div class="panel">
    <?php if ($list['rows'] === []): ?><div class="table-empty">No claims raised.</div><?php endif ?>
    <ul class="tap-list">
    <?php foreach ($list['rows'] as $c): ?>
        <li><a href="<?= site_url('dealer/claims/' . $c['id']) ?>">
            <span><strong><?= esc($c['claim_number']) ?></strong>
                <span class="d-block small text-muted"><?= esc($c['reported_issue']) ?></span>
                <span class="d-block small text-muted mono"><?= esc($c['serial_number']) ?> · <?= esc(local_date($c['submitted_at'])) ?></span></span>
            <span><?= pill($c['status']) ?></span>
        </a></li>
    <?php endforeach ?>
    </ul>
    <?= view('partials/pager', ['p' => $list]) ?>
</div>
<?= $this->endSection() ?>
