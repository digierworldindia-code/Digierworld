<?= $this->extend('layouts/portal') ?>
<?= $this->section('content') ?>
<?php /** @var array $list */ ?>
<h1 class="h4 mb-2">Incoming stock</h1>
<p class="small text-muted">Confirm each consignment when it arrives, unit by unit.</p>
<div class="panel">
    <?php if ($list['rows'] === []): ?><div class="table-empty">Nothing on its way.</div><?php endif ?>
    <ul class="tap-list">
    <?php foreach ($list['rows'] as $d): ?>
        <li><a href="<?= site_url('dealer/incoming/' . $d['id']) ?>">
            <span><strong class="mono"><?= esc($d['dispatch_code']) ?></strong>
                <span class="d-block small text-muted"><?= (int) $d['units'] ?> unit(s) · sent <?= esc(local_date($d['dispatched_at'])) ?><?= $d['transporter'] ? ' · ' . esc($d['transporter']) : '' ?></span></span>
            <span><?= pill($d['status']) ?> <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i></span>
        </a></li>
    <?php endforeach ?>
    </ul>
    <?= view('partials/pager', ['p' => $list]) ?>
</div>
<?= $this->endSection() ?>
