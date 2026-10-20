<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<form method="post" action="<?= site_url('notifications/read-all') ?>"><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm"><i class="bi bi-check2-all"></i> Mark all read</button></form>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="table-card">
    <?php if ($rows === []): ?>
        <div class="empty-state"><i class="bi bi-bell"></i>No notifications yet. Follow-up reminders, approvals and sync alerts will appear here.</div>
    <?php else: ?>
    <div class="list-group list-group-flush">
        <?php foreach ($rows as $n): ?>
            <a href="<?= site_url('notifications/' . $n['id'] . '/open') ?>" class="list-group-item list-group-item-action <?= $n['read_at'] ? '' : 'bg-gold-soft' ?>">
                <div class="d-flex justify-content-between"><strong class="small"><?= esc($n['title']) ?></strong><span class="small text-muted"><?= dmy($n['created_at'], true) ?></span></div>
                <?php if ($n['body']): ?><div class="small text-muted"><?= esc($n['body']) ?></div><?php endif ?>
            </a>
        <?php endforeach ?>
    </div>
    <?php endif ?>
</div>
<?= view('partials/pager', ['total' => $total, 'page' => $page, 'perPage' => $perPage]) ?>
<?= $this->endSection() ?>
