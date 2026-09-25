<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var array $list */ ?>
<div class="page-head"><div><h1>Notifications</h1><p>Exceptions and events that need a person to look.</p></div></div>
<div class="panel">
    <?php if ($list['rows'] === []): ?><div class="table-empty">Nothing to show.</div><?php endif ?>
    <ul class="tap-list">
    <?php foreach ($list['rows'] as $n): ?>
        <li><div class="row-item<?= $n['read_at'] === null ? ' bg-white' : '' ?>">
            <div>
                <strong><?= esc($n['title']) ?></strong> <?= $n['read_at'] === null ? '<span class="pill pill-accent">New</span>' : '' ?>
                <div class="small text-muted"><?= esc(humanise($n['type'])) ?> · <?= esc(local_time($n['created_at'])) ?></div>
                <?php if ($n['body']): ?><div class="small mt-1" style="white-space:pre-line"><?= esc($n['body']) ?></div><?php endif ?>
            </div>
            <?php if ($n['read_at'] === null): ?>
            <form method="post" action="<?= site_url('admin/notifications/' . $n['id'] . '/read') ?>"><?= csrf_field() ?><button class="btn btn-light btn-sm" type="submit">Mark read</button></form>
            <?php endif ?>
        </div></li>
    <?php endforeach ?>
    </ul>
    <?= view('partials/pager', ['p' => $list]) ?>
</div>
<?= $this->endSection() ?>
