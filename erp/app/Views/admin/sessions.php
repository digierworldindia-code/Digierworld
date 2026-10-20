<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="table-card"><div class="table-responsive">
<table class="table table-hover align-middle">
    <thead><tr><th>User</th><th>Device</th><th>IP</th><th>Started</th><th>Last activity</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
        <tr>
            <td><a href="<?= site_url('admin/users/' . $s['user_id']) ?>"><?= esc($s['full_name'] ?? ('User #' . $s['user_id'])) ?></a><?= $s['session_id'] === $currentSid ? ' <span class="badge badge-gold">you</span>' : '' ?></td>
            <td class="small"><?= esc(mb_strimwidth((string) $s['user_agent'], 0, 60, '…')) ?></td>
            <td class="small mono"><?= esc($s['ip_address']) ?></td>
            <td class="small"><?= dmy($s['created_at'], true) ?></td>
            <td class="small"><?= dmy($s['last_activity_at'], true) ?></td>
            <td class="text-end"><?php if ($s['session_id'] !== $currentSid): ?>
                <form method="post" action="<?= site_url('admin/sessions/' . $s['id'] . '/revoke') ?>" data-confirm="Sign this device out now?" data-confirm-danger><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger">Revoke</button></form>
            <?php endif ?></td>
        </tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="6"><div class="empty-state"><i class="bi bi-display"></i>No active sessions.</div></td></tr><?php endif ?>
    </tbody>
</table>
</div></div>
<?= $this->endSection() ?>
