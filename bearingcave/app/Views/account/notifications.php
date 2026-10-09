<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Notifications', 'actions' => post_button('notifications/read-all', 'Mark all as read', 'btn btn-light btn-sm')]) ?>
<div class="bc-card">
<?php if (! $items): ?><?= empty_state('bi-bell', 'You are all caught up') ?><?php else: ?>
    <ul class="list-group list-group-flush">
    <?php foreach ($items as $n): ?>
        <li class="list-group-item d-flex gap-3 align-items-start <?= $n['read_at'] ? '' : 'bg-soft' ?>">
            <i class="bi <?= $n['read_at'] ? 'bi-bell' : 'bi-bell-fill text-primary' ?> mt-1" aria-hidden="true"></i>
            <div class="flex-grow-1"><div class="fw-semibold"><?= esc($n['title']) ?><?= $n['read_at'] ? '' : ' <span class="visually-hidden">(unread)</span>' ?></div><?php if ($n['body']): ?><div class="small text-muted"><?= esc($n['body']) ?></div><?php endif ?><div class="small text-muted"><?= fdt($n['created_at']) ?></div></div>
            <?php if ($n['link'] || ! $n['read_at']): ?><?= post_button('notifications/' . $n['id'] . '/read', $n['link'] ? 'Open' : 'Mark read', 'btn btn-sm btn-light') ?><?php endif ?>
        </li>
    <?php endforeach ?>
    </ul>
    <?= $pager->links() ?>
<?php endif ?>
</div>
<?= $this->endSection() ?>
