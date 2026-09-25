<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php
use App\Libraries\Rbac;

/** @var array $list @var array $f */
$temp = session()->getFlashdata('temporary_password');
?>
<div class="page-head">
    <div><h1>Users</h1><p>Staff and dealer logins. A dealer login holds the Dealer role only.</p></div>
    <?php if ($ctx->can('user:write')): ?><a class="btn btn-primary" href="<?= site_url('admin/users/new') ?>"><i class="bi bi-plus-lg"></i> New user</a><?php endif ?>
</div>
<?php if ($temp): ?>
<?php [$password, $email] = explode('|', $temp, 2); ?>
<div class="panel mb-3"><div class="panel-head"><h2>Temporary password for <?= esc($email) ?></h2></div>
    <div class="panel-body">
        <p class="mono fs-5 mb-2"><?= esc($password) ?></p>
        <p class="small text-muted mb-0">Shown once and not stored in readable form. Hand it over in person or by phone — not by email. They must change it at first sign-in.</p>
    </div></div>
<?php endif ?>
<div class="panel">
    <form class="filters panel-body border-bottom" method="get">
        <div><label class="form-label" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= esc($f['q'] ?? '', 'attr') ?>" placeholder="Name or email"></div>
        <div><label class="form-label" for="role">Role</label><select class="form-select" id="role" name="role" data-autosubmit><option value="">Any</option>
            <?php foreach (Rbac::ROLE_KEYS as $r): ?><option value="<?= $r ?>"<?= $f['role'] === $r ? ' selected' : '' ?>><?= esc(Rbac::roleName($r)) ?></option><?php endforeach ?></select></div>
        <div><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status" data-autosubmit><option value="">Any</option>
            <?php foreach (['ACTIVE', 'SUSPENDED', 'DISABLED', 'PENDING_ACTIVATION'] as $s): ?><option value="<?= $s ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= esc(humanise($s)) ?></option><?php endforeach ?></select></div>
        <div><button class="btn btn-light" type="submit">Filter</button></div>
    </form>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Name</th><th>Email</th><th>Roles</th><th>Dealer</th><th>Status</th><th>2FA</th><th>Last sign-in</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $u): ?>
        <tr><td><a class="fw-semibold" href="<?= site_url('admin/users/' . $u['id']) ?>"><?= esc($u['full_name']) ?></a>
                <?= $u['locked_until'] && strtotime($u['locked_until'] . ' UTC') > time() ? ' <span class="pill pill-critical">Locked</span>' : '' ?>
                <?= $u['must_change_password'] ? ' <span class="pill pill-caution">Temp password</span>' : '' ?></td>
            <td class="small"><?= esc($u['email']) ?></td>
            <td class="small"><?= esc(implode(', ', array_map(static fn ($r) => Rbac::roleName($r), array_filter(explode(',', (string) $u['roles']))))) ?></td>
            <td class="small"><?= esc($u['dealer'] ?? '—') ?></td>
            <td><?= pill($u['status']) ?></td>
            <td><?= $u['mfa_enabled'] ? '<span class="pill pill-positive">On</span>' : '<span class="pill">Off</span>' ?></td>
            <td class="small text-nowrap"><?= esc(local_time($u['last_login_at'], 'd M Y')) ?></td></tr>
        <?php endforeach ?>
        <?php if ($list['rows'] === []): ?><tr><td colspan="7" class="table-empty">No users match.</td></tr><?php endif ?>
        </tbody>
    </table></div>
    <?= view('partials/pager', ['p' => $list]) ?>
</div>
<?= $this->endSection() ?>
