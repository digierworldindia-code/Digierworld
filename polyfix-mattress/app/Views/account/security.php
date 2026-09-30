<?= $this->extend($layout) ?>
<?= $this->section('content') ?>
<?php
/** @var array $user @var list<array> $sessions */
$title = 'Password & security';
?>
<div class="page-head"><div><h1>Password &amp; security</h1><p><?= esc($user['email']) ?></p></div></div>

<div class="row g-3">
    <div class="col-12">
        <div class="panel h-100" id="password">
            <div class="panel-head"><h2>Change password</h2></div>
            <form class="panel-body" method="post" action="<?= site_url('account/password') ?>">
                <?= csrf_field() ?>
                <?php if ($user['must_change_password']): ?><div class="alert alert-warning">You signed in with a temporary password. Choose your own to continue.</div><?php endif ?>
                <div class="mb-3"><label class="form-label" for="current_password">Current password</label>
                    <input class="form-control" id="current_password" name="current_password" type="password" autocomplete="current-password" required maxlength="200"></div>
                <div class="mb-3"><label class="form-label" for="new_password">New password</label>
                    <input class="form-control" id="new_password" name="new_password" type="password" autocomplete="new-password" required minlength="12" maxlength="200">
                    <div class="form-text">At least 12 characters with upper and lower case letters, a digit and a symbol; not your name or email.</div></div>
                <div class="mb-3"><label class="form-label" for="new_password_confirm">Repeat new password</label>
                    <input class="form-control" id="new_password_confirm" name="new_password_confirm" type="password" autocomplete="new-password" required minlength="12" maxlength="200"></div>
                <button class="btn btn-primary" type="submit">Change password</button>
            </form>
        </div>
    </div>

</div>

<div class="panel mt-3">
    <div class="panel-head"><h2>Signed-in sessions</h2>
        <form method="post" action="<?= site_url('account/sessions/revoke-others') ?>" data-confirm="Sign out every other session?"><?= csrf_field() ?><button class="btn btn-light btn-sm" type="submit">Sign out other sessions</button></form>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Device</th><th>IP address</th><th>Signed in</th><th>Last active</th></tr></thead>
            <tbody>
            <?php foreach ($sessions as $s): ?>
                <tr>
                    <td class="small"><?= esc(mb_strimwidth((string) $s['user_agent'], 0, 70, '…')) ?><?= $s['id'] === service('requestContext')->sessionId() ? ' <span class="pill pill-accent">This session</span>' : '' ?></td>
                    <td class="mono"><?= esc($s['ip']) ?></td>
                    <td class="small"><?= esc(local_time($s['created_at'])) ?></td>
                    <td class="small"><?= esc(local_time($s['last_seen_at'])) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
