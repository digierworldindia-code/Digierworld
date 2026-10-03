<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="qms-page-head">
    <div><h1>Users</h1><p class="qms-sub">Login accounts. Accounts are disabled, never deleted.</p></div>
    <a class="btn btn-primary btn-lg" href="<?= site_url('admin/users/new') ?>"><?= qms_icon('bi-person-plus') ?> New user</a>
</div>
<form class="qms-filter row g-2 align-items-end" method="get">
    <div class="col-md-4"><label class="form-label" for="q">Search</label>
        <input class="form-control" id="q" name="q" value="<?= esc($filters['q'] ?? '', 'attr') ?>" placeholder="Username, name or employee code"></div>
    <div class="col-md-3"><label class="form-label" for="role_id">Role</label>
        <select class="form-select" id="role_id" name="role_id"><option value="">All roles</option>
            <?php foreach ($roles as $role): ?><option value="<?= $role['id'] ?>"<?= (string) ($filters['role_id'] ?? '') === (string) $role['id'] ? ' selected' : '' ?>><?= esc($role['name']) ?></option><?php endforeach ?>
        </select></div>
    <div class="col-md-3"><label class="form-label" for="status">Status</label>
        <select class="form-select" id="status" name="status"><option value="">All</option>
            <?php foreach (['ACTIVE' => 'Active', 'DISABLED' => 'Disabled'] as $k => $v): ?><option value="<?= $k ?>"<?= ($filters['status'] ?? '') === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach ?>
        </select></div>
    <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit"><?= qms_icon('bi-funnel') ?> Filter</button></div>
</form>
<div class="card"><div class="qms-table-wrap">
<table class="table table-hover">
    <thead><tr><th>Username</th><th>Employee</th><th>Role</th><th>Status</th><th>Last login</th><th></th></tr></thead>
    <tbody>
    <?php if ($users === []): ?><tr><td colspan="6" class="qms-empty"><?= qms_icon('bi-people') ?>No users found.</td></tr><?php endif ?>
    <?php foreach ($users as $u): ?>
        <tr>
            <td><strong><?= esc($u['username']) ?></strong><?php if ((int) $u['must_change_password'] === 1): ?> <span class="qms-badge qms-badge--pending">must change password</span><?php endif ?></td>
            <td><?= esc(trim(($u['employee_code'] ?? '') . ' ' . ($u['full_name'] ?? ''))) ?: '<span class="text-muted">—</span>' ?></td>
            <td><?= esc($u['role_name']) ?></td>
            <td>
                <?= $u['status'] === 'ACTIVE' ? '<span class="qms-badge qms-badge--pass">' . qms_icon('bi-check-circle') . ' Active</span>' : '<span class="qms-badge qms-badge--muted">' . qms_icon('bi-slash-circle') . ' Disabled</span>' ?>
                <?php if ($u['locked_until'] !== null && $u['locked_until'] > service('clock')->nowUtcString()): ?><span class="qms-badge qms-badge--fail"><?= qms_icon('bi-lock-fill') ?> Locked</span><?php endif ?>
            </td>
            <td class="num"><?= esc(plant_dt($u['last_login_at'])) ?: '<span class="text-muted">Never</span>' ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= site_url('admin/users/' . $u['id'] . '/edit') ?>"><?= qms_icon('bi-pencil') ?> Edit</a></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div></div>
<?= view('partials/pager', ['paging' => $paging]) ?>
<?= $this->endSection() ?>
