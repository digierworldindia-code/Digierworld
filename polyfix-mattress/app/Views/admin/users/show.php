<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php
use App\Libraries\Rbac;

/** @var array $u @var list<string> $roles @var list<array> $sessions @var list<array> $recent @var bool $canManage */
$temp = session()->getFlashdata('temporary_password');
$self = $u['id'] === $ctx->userId();
?>
<div class="page-head">
    <div><div class="small"><a href="<?= site_url('admin/users') ?>">Users</a></div>
    <h1><?= esc($u['full_name']) ?> <?= pill($u['status']) ?></h1>
    <p><?= esc($u['email']) ?> · <?= esc(implode(', ', array_map(static fn ($r) => Rbac::roleName($r), $roles))) ?><?= $u['dealer'] ? ' · ' . esc($u['dealer']) : '' ?></p></div>
</div>
<?php if ($temp): ?>
<?php [$password, $email] = explode('|', $temp, 2); ?>
<div class="panel mb-3"><div class="panel-head"><h2>Temporary password for <?= esc($email) ?></h2></div>
    <div class="panel-body"><p class="mono fs-5 mb-2"><?= esc($password) ?></p>
    <p class="small text-muted mb-0">Shown once. Hand it over in person or by phone — not by email.</p></div></div>
<?php endif ?>
<?php if (! $canManage): ?><div class="alert alert-secondary">This account holds a role senior to yours, so you can view it but not change it.</div><?php endif ?>

<div class="row g-3">
    <div class="col-xl-6">
        <?php if ($ctx->can('user:write') && $canManage): ?>
        <form class="panel" method="post" action="<?= site_url('admin/users/' . $u['id']) ?>">
            <div class="panel-head"><h2>Account</h2></div>
            <div class="panel-body"><?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label" for="full_name">Full name</label><input class="form-control" id="full_name" name="full_name" required maxlength="160" value="<?= esc($u['full_name'], 'attr') ?>"></div>
                    <div class="col-md-6"><label class="form-label" for="phone">Phone</label><input class="form-control" id="phone" name="phone" type="tel" maxlength="16" value="<?= esc($u['phone'] ?? '', 'attr') ?>"></div>
                    <div class="col-md-6"><label class="form-label" for="status">Status</label>
                        <select class="form-select" id="status" name="status"<?= $self ? ' disabled' : '' ?>>
                            <?php foreach (['ACTIVE', 'SUSPENDED', 'DISABLED'] as $s): ?><option value="<?= $s ?>"<?= $u['status'] === $s ? ' selected' : '' ?>><?= esc(humanise($s)) ?></option><?php endforeach ?>
                        </select>
                        <?php if ($self): ?><input type="hidden" name="status" value="<?= esc($u['status'], 'attr') ?>"><div class="form-text">You cannot suspend your own account.</div><?php endif ?></div>
                </div>
                <button class="btn btn-primary mt-3" type="submit">Save</button>
            </div>
        </form>
        <?php endif ?>

        <div class="panel"><div class="panel-head"><h2>Security</h2></div><div class="panel-body"><dl class="dl-grid">
            <dt>Two-factor</dt><dd><?= $u['mfa_enabled'] ? 'On since ' . esc(local_date($u['mfa_enrolled_at'])) : 'Off' ?></dd>
            <dt>Password changed</dt><dd><?= esc(local_time($u['password_changed_at'])) ?><?= $u['must_change_password'] ? ' · must change at next sign-in' : '' ?></dd>
            <dt>Failed attempts</dt><dd><?= (int) $u['failed_login_count'] ?><?= $u['locked_until'] && strtotime($u['locked_until'] . ' UTC') > time() ? ' · locked until ' . esc(local_time($u['locked_until'])) : '' ?></dd>
            <dt>Last sign-in</dt><dd><?= esc(local_time($u['last_login_at'])) ?><?= $u['last_login_ip'] ? ' from ' . esc($u['last_login_ip']) : '' ?></dd>
            <dt>Created</dt><dd><?= esc(local_time($u['created_at'])) ?></dd>
        </dl></div></div>

        <div class="panel"><div class="panel-head"><h2>Signed-in sessions</h2></div>
            <?php if ($sessions === []): ?><div class="table-empty">No active sessions.</div><?php else: ?>
            <table class="table"><tbody><?php foreach ($sessions as $s): ?>
                <tr><td class="small"><?= esc(mb_strimwidth((string) $s['user_agent'], 0, 60, '…')) ?></td><td class="mono small"><?= esc($s['ip']) ?></td>
                <td class="small text-nowrap"><?= esc(local_time($s['last_seen_at'])) ?></td></tr>
            <?php endforeach ?></tbody></table><?php endif ?>
        </div>
    </div>

    <div class="col-xl-6">
        <?php if ($ctx->can('user:role:assign') && $canManage && ! $self): ?>
        <form class="panel" method="post" action="<?= site_url('admin/users/' . $u['id'] . '/roles') ?>" data-confirm="Change this account's roles? Their sessions end immediately.">
            <div class="panel-head"><h2>Roles</h2></div>
            <div class="panel-body"><?= csrf_field() ?>
                <?php foreach (Rbac::ROLE_KEYS as $r): ?>
                <div class="form-check"><input class="form-check-input" type="checkbox" id="role-<?= $r ?>" name="roles[]" value="<?= $r ?>"<?= in_array($r, $roles, true) ? ' checked' : '' ?>>
                    <label class="form-check-label" for="role-<?= $r ?>"><?= esc(Rbac::roleName($r)) ?> <span class="small text-muted"><?= esc(Rbac::ROLE_METADATA[$r]['description'] ?? '') ?></span></label></div>
                <?php endforeach ?>
                <div class="mt-3"><label class="form-label" for="reason">Reason</label><input class="form-control" id="reason" name="reason" maxlength="500"></div>
                <button class="btn btn-primary mt-3" type="submit">Save roles</button>
                <p class="small text-muted mt-2 mb-0">You cannot grant a role senior to your own, or change your own roles.</p>
            </div>
        </form>
        <?php elseif ($self): ?>
        <div class="panel"><div class="panel-head"><h2>Roles</h2></div><div class="panel-body">
            <p class="small mb-0">You cannot change your own roles. Ask another administrator.</p></div></div>
        <?php endif ?>

        <?php if ($ctx->can('user:reset_password') && $canManage): ?>
        <form class="panel" method="post" action="<?= site_url('admin/users/' . $u['id'] . '/force-password-reset') ?>" data-confirm="Reset this password? Their sessions end and a one-time password is issued.">
            <div class="panel-head"><h2>Reset password</h2></div>
            <div class="panel-body"><?= csrf_field() ?>
                <div class="d-flex gap-2"><input class="form-control" name="reason" maxlength="500" placeholder="Reason"><button class="btn btn-light" type="submit">Reset</button></div>
            </div>
        </form>
        <?php if ($u['mfa_enabled']): ?>
        <form class="panel" method="post" action="<?= site_url('admin/users/' . $u['id'] . '/reset-mfa') ?>" data-confirm="Clear two-factor authentication for this account?">
            <div class="panel-head"><h2>Reset two-factor</h2></div>
            <div class="panel-body"><?= csrf_field() ?>
                <p class="small text-muted">For a lost or replaced phone. They enrol a new device at next sign-in.</p>
                <div class="d-flex gap-2"><input class="form-control" name="reason" maxlength="500" placeholder="Reason"><button class="btn btn-light" type="submit">Clear 2FA</button></div>
            </div>
        </form>
        <?php endif ?>
        <?php endif ?>

        <div class="panel"><div class="panel-head"><h2>Recent activity</h2><?php if ($ctx->can('audit:read')): ?><a class="small" href="<?= site_url('admin/audit?user=' . $u['id']) ?>">Audit trail</a><?php endif ?></div>
            <?php if ($recent === []): ?><div class="table-empty">Nothing recorded.</div><?php else: ?>
            <table class="table"><tbody><?php foreach ($recent as $a): ?>
                <tr><td class="small text-nowrap"><?= esc(local_time($a['occurred_at'])) ?></td><td class="small"><?= esc(humanise($a['action'])) ?></td><td class="small text-muted"><?= esc($a['entity']) ?></td></tr>
            <?php endforeach ?></tbody></table><?php endif ?>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
