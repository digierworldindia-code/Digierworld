<?php
/**
 * Login accounts.
 */
require dirname(__DIR__) . '/includes/init.php';
$user = require_permission('user.manage');
require_once QMS_ROOT . '/includes/users.php';

$filters = ['q' => get('q'), 'role_id' => get('role_id'), 'status' => get('status')];
$paging  = paging(30);
$users   = user_list($filters, $paging);
$roles   = user_roles();
$now     = now_str();

$page_title = 'Users';
require QMS_ROOT . '/includes/layout/header.php';
?>
<div class="qms-page-head">
    <div><h1>Users</h1><p class="qms-sub">Login accounts. Accounts are disabled, never deleted.</p></div>
    <a class="btn btn-primary btn-lg" href="<?= url('admin/user_edit.php') ?>"><?= qms_icon('bi-person-plus') ?> New user</a>
</div>
<form class="qms-filter row g-2 align-items-end" method="get" action="<?= url('admin/users.php') ?>">
    <div class="col-md-4"><label class="form-label" for="q">Search</label>
        <input class="form-control" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="Username, name or employee code"></div>
    <div class="col-md-3"><label class="form-label" for="role_id">Role</label>
        <select class="form-select" id="role_id" name="role_id"><option value="">All roles</option>
            <?php foreach ($roles as $role): ?><option value="<?= (int) $role['id'] ?>"<?= $filters['role_id'] === (string) $role['id'] ? ' selected' : '' ?>><?= e($role['name']) ?></option><?php endforeach ?>
        </select></div>
    <div class="col-md-3"><label class="form-label" for="status">Status</label>
        <select class="form-select" id="status" name="status"><option value="">All</option>
            <?php foreach (['ACTIVE' => 'Active', 'DISABLED' => 'Disabled'] as $k => $v): ?><option value="<?= $k ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach ?>
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
            <td><strong><?= e($u['username']) ?></strong><?php if ((int) $u['must_change_password'] === 1): ?> <span class="qms-badge qms-badge--pending">must change password</span><?php endif ?></td>
            <td><?= e(trim(($u['employee_code'] ?? '') . ' ' . ($u['full_name'] ?? ''))) ?: '<span class="text-muted">—</span>' ?></td>
            <td><?= e($u['role_name']) ?></td>
            <td>
                <?= $u['status'] === 'ACTIVE' ? '<span class="qms-badge qms-badge--pass">' . qms_icon('bi-check-circle') . ' Active</span>' : '<span class="qms-badge qms-badge--muted">' . qms_icon('bi-slash-circle') . ' Disabled</span>' ?>
                <?php if ($u['locked_until'] !== null && $u['locked_until'] > $now): ?><span class="qms-badge qms-badge--fail"><?= qms_icon('bi-lock-fill') ?> Locked</span><?php endif ?>
            </td>
            <td class="num"><?= e(plant_dt($u['last_login_at'])) ?: '<span class="text-muted">Never</span>' ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('admin/user_edit.php?id=' . (int) $u['id']) ?>"><?= qms_icon('bi-pencil') ?> Edit</a></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div></div>
<?php require QMS_ROOT . '/includes/views/pager.php'; ?>
<?php require QMS_ROOT . '/includes/layout/footer.php';
