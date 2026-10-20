<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-body">
                <div class="card-section-title">Account</div>
                <dl class="row mb-0 small">
                    <dt class="col-5">Name</dt><dd class="col-7"><?= esc($profile['full_name'] ?? '') ?></dd>
                    <dt class="col-5">Email (login)</dt><dd class="col-7"><?= esc($email) ?></dd>
                    <dt class="col-5">Phone</dt><dd class="col-7"><?= esc($profile['phone'] ?? '—') ?></dd>
                    <dt class="col-5">Roles</dt>
                    <dd class="col-7"><?php foreach ($roles as $r): ?><span class="badge badge-neutral me-1"><?= esc($r['name']) ?></span><?php if ($r['expires_at']): ?><span class="small text-muted">until <?= dmy($r['expires_at'], true) ?></span><?php endif ?><?php endforeach ?></dd>
                    <dt class="col-5">Permissions</dt><dd class="col-7"><?= count($perms) ?> effective permissions</dd>
                </dl>
                <a href="<?= site_url('profile/password') ?>" class="btn btn-outline-secondary btn-sm mt-3"><i class="bi bi-key"></i> Change password</a>
            </div>
        </div>
        <div class="card mt-3">
            <div class="card-body">
                <div class="card-section-title">Recent sign-ins</div>
                <ul class="list-unstyled small mb-0">
                    <?php foreach ($logins as $l): ?>
                        <li class="d-flex justify-content-between border-bottom py-1">
                            <span><?= dmy($l['date'], true) ?> · <?= esc($l['ip_address']) ?></span>
                            <span><?= $l['success'] ? '<span class="text-success">success</span>' : '<span class="text-danger">failed</span>' ?></span>
                        </li>
                    <?php endforeach ?>
                    <?php if ($logins === []): ?><li class="text-muted">No sign-ins recorded.</li><?php endif ?>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="table-card">
            <div class="p-3 border-bottom"><strong>Active sessions</strong><div class="small text-muted">Sign out sessions you don't recognise. Administrators can also revoke sessions.</div></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead><tr><th>Device</th><th>IP</th><th>Last activity</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($sessions as $s): ?>
                        <tr>
                            <td class="small"><?= esc(mb_strimwidth((string) $s['user_agent'], 0, 60, '…')) ?><?= $s['session_id'] === $currentSid ? ' <span class="badge badge-gold">this device</span>' : '' ?></td>
                            <td class="small mono"><?= esc($s['ip_address']) ?></td>
                            <td class="small"><?= dmy($s['last_activity_at'], true) ?></td>
                            <td><?= $s['revoked_at'] ? '<span class="badge badge-neutral">Signed out</span>' : '<span class="badge badge-success">Active</span>' ?></td>
                            <td class="text-end">
                                <?php if (! $s['revoked_at'] && $s['session_id'] !== $currentSid): ?>
                                    <form method="post" action="<?= site_url('profile/sessions/' . $s['id'] . '/revoke') ?>" data-confirm="Sign out this session?"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger">Sign out</button></form>
                                <?php endif ?>
                            </td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
