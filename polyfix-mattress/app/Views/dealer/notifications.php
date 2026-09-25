<?= $this->extend('layouts/portal') ?>
<?= $this->section('content') ?>
<?php /** @var array $list */ ?>
<h1 class="h4 mb-2">Notifications</h1>
<div class="panel">
    <?php if ($list['rows'] === []): ?><div class="table-empty">Nothing to show.</div><?php endif ?>
    <ul class="tap-list">
    <?php foreach ($list['rows'] as $n): ?>
        <li><div class="row-item">
            <span><strong><?= esc($n['title']) ?></strong> <?= $n['read_at'] === null ? '<span class="pill pill-accent">New</span>' : '' ?>
                <span class="d-block small text-muted"><?= esc(local_time($n['created_at'])) ?></span>
                <?php if ($n['body']): ?><span class="d-block small mt-1" style="white-space:pre-line"><?= esc($n['body']) ?></span><?php endif ?></span>
            <?php if ($n['read_at'] === null): ?>
            <form method="post" action="<?= site_url('dealer/notifications/' . $n['id'] . '/read') ?>"><?= csrf_field() ?><button class="btn btn-light btn-sm" type="submit">Read</button></form>
            <?php endif ?>
        </div></li>
    <?php endforeach ?>
    </ul>
    <?= view('partials/pager', ['p' => $list]) ?>
</div>
<?= $this->endSection() ?>
