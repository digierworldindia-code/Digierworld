<?= $this->extend($layout) ?>
<?= $this->section('content') ?>
<?php
/** @var array $user @var list<array> $sessions @var array|null $setup @var list<string>|null $codes @var bool $enrol */
$title = 'Password & security';
?>
<div class="page-head"><div><h1>Password &amp; security</h1><p><?= esc($user['email']) ?></p></div></div>

<?php if ($codes): ?>
<div class="panel mb-3 border-warning">
    <div class="panel-head"><h2>Your recovery codes</h2></div>
    <div class="panel-body">
        <p class="small">Each code works once, in place of the code from your phone. Store them somewhere safe — a password manager, or printed and locked away. They are not shown again.</p>
        <div class="recovery-codes"><?php foreach ($codes as $c): ?><div><?= esc($c) ?></div><?php endforeach ?></div>
    </div>
</div>
<?php endif ?>

<div class="row g-3">
    <div class="col-xl-6">
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

    <div class="col-xl-6">
        <div class="panel h-100" id="mfa">
            <div class="panel-head"><h2>Two-factor authentication</h2>
                <?= $user['mfa_enabled'] ? '<span class="pill pill-positive">On</span>' : '<span class="pill pill-caution">Off</span>' ?></div>
            <div class="panel-body">
                <?php if ($user['mfa_enabled']): ?>
                    <p class="small text-muted">Signing in needs your password and a code from your authenticator app.</p>
                    <?php if (array_intersect($user['roles'], config('Polyfix')->mfaRoles()) === []): ?>
                    <form method="post" action="<?= site_url('account/mfa/disable') ?>" data-confirm="Switch off two-factor authentication?">
                        <?= csrf_field() ?>
                        <div class="row g-2">
                            <div class="col-sm-6"><label class="form-label" for="mfa-off-password">Password</label><input class="form-control" id="mfa-off-password" name="password" type="password" autocomplete="current-password" required></div>
                            <div class="col-sm-6"><label class="form-label" for="mfa-off-code">Code from app</label><input class="form-control mono" id="mfa-off-code" name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="8"></div>
                        </div>
                        <button class="btn btn-light mt-3" type="submit">Switch off</button>
                    </form>
                    <?php else: ?>
                    <p class="small mb-0">Your role requires it, so it stays on. To move it to a new phone, ask an administrator to reset it.</p>
                    <?php endif ?>
                <?php elseif ($setup !== null): ?>
                    <ol class="small ps-3">
                        <li>Open an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password…).</li>
                        <li>Scan this code, or type the key below.</li>
                        <li>Enter the 6-digit code the app shows.</li>
                    </ol>
                    <div class="d-flex flex-wrap gap-3 align-items-center">
                        <img src="data:image/svg+xml;base64,<?= base64_encode($setup['qr']) ?>" width="180" height="180" alt="QR code for your authenticator app">
                        <div class="small"><div class="text-muted">Setup key</div><code class="d-block text-break"><?= esc(trim(chunk_split($setup['secret'], 4, ' '))) ?></code></div>
                    </div>
                    <form class="mt-3" method="post" action="<?= site_url('account/mfa/confirm') ?>">
                        <?= csrf_field() ?>
                        <label class="form-label" for="mfa-code">Code from app</label>
                        <div class="d-flex gap-2"><input class="form-control mono" id="mfa-code" name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="8" style="max-width:10rem" autofocus>
                        <button class="btn btn-primary" type="submit">Turn on</button></div>
                    </form>
                <?php else: ?>
                    <?php if ($enrol): ?><div class="alert alert-warning">Your role requires two-factor authentication. Set it up to continue.</div><?php endif ?>
                    <p class="small text-muted">Adds a code from your phone to every sign-in, so a stolen password alone is not enough.</p>
                    <form method="post" action="<?= site_url('account/mfa/start') ?>">
                        <?= csrf_field() ?>
                        <label class="form-label" for="mfa-password">Confirm your password</label>
                        <div class="d-flex gap-2"><input class="form-control" id="mfa-password" name="password" type="password" autocomplete="current-password" required style="max-width:16rem">
                        <button class="btn btn-primary" type="submit">Set up</button></div>
                    </form>
                <?php endif ?>
            </div>
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
