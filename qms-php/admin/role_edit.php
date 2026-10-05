<?php
/**
 * Permission matrix of one role (admin/role_edit.php?id=3). POST action: save or delete.
 */
require dirname(__DIR__) . '/includes/init.php';
$user = require_permission('role.manage');
require_once QMS_ROOT . '/includes/users.php';

$id = get_int('id');
if (is_post()) {
    try {
        if (post('action') === 'delete') {
            role_delete($id, $user);
            done('Role deleted.', 'admin/roles.php');
        }
        role_update($id, post('name'), post('description'), post_list('permissions'), $user);
    } catch (Throwable $e) {
        back_with_error($e, 'admin/role_edit.php?id=' . $id);
    }
    done('Role saved. The new permissions apply on the users\' next click.', 'admin/role_edit.php?id=' . $id);
}

$role     = role_find($id);
$matrix   = role_matrix($id);
$readOnly = (int) $role['is_super_admin'] === 1 || (int) $user['role_id'] === $id;

$page_title = 'Role ' . $role['name'];
require QMS_ROOT . '/includes/layout/header.php';
?>
<div class="qms-page-head">
    <div><h1><?= e($role['name']) ?></h1><p class="qms-sub"><code><?= e($role['code']) ?></code>
        <?= (int) $role['is_super_admin'] === 1 ? ' · holds every permission (read-only)' : '' ?></p></div>
    <a class="btn btn-outline-secondary" href="<?= url('admin/roles.php') ?>"><?= qms_icon('bi-arrow-left') ?> Back</a>
</div>
<?php if ($readOnly && (int) $role['is_super_admin'] !== 1): ?>
    <div class="alert alert-warning">This is your own role. Another administrator must change it.</div>
<?php endif ?>
<form method="post" action="<?= url('admin/role_edit.php?id=' . (int) $role['id']) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="card mb-3"><div class="card-body row g-3">
        <div class="col-md-5"><label class="form-label required" for="name">Name</label>
            <input class="form-control" id="name" name="name" maxlength="60" value="<?= e($role['name']) ?>" <?= $readOnly ? 'disabled' : 'required' ?>></div>
        <div class="col-md-7"><label class="form-label" for="description">Description</label>
            <input class="form-control" id="description" name="description" maxlength="255" value="<?= e($role['description'] ?? '') ?>" <?= $readOnly ? 'disabled' : '' ?>></div>
    </div></div>
    <div class="row g-3">
    <?php foreach ($matrix as $module => $permissions): ?>
        <div class="col-md-6 col-xl-4">
            <div class="card h-100"><div class="card-header text-capitalize"><?= e($module) ?></div><div class="card-body">
                <?php foreach ($permissions as $p): ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="permissions[]" value="<?= e($p['code']) ?>" id="p<?= (int) $p['id'] ?>"
                            <?= $p['granted'] ? 'checked' : '' ?> <?= $readOnly ? 'disabled' : '' ?>>
                        <label class="form-check-label" for="p<?= (int) $p['id'] ?>"><code class="small"><?= e($p['code']) ?></code><br><span class="small text-muted"><?= e($p['description']) ?></span></label>
                    </div>
                <?php endforeach ?>
            </div></div>
        </div>
    <?php endforeach ?>
    </div>
    <?php if (! $readOnly): ?>
    <div class="qms-actionbar">
        <span class="qms-progress">Changes apply on the users' next click.</span>
        <button class="btn btn-primary btn-lg" type="submit" data-once><?= qms_icon('bi-check2') ?> Save permissions</button>
    </div>
    <?php endif ?>
</form>
<?php if (! $readOnly && (int) $role['is_system'] !== 1): ?>
<form method="post" action="<?= url('admin/role_edit.php?id=' . (int) $role['id']) ?>" class="mt-3" data-confirm="Delete this role?">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete">
    <button class="btn btn-outline-danger" type="submit"><?= qms_icon('bi-trash') ?> Delete role</button>
</form>
<?php endif ?>
<?php require QMS_ROOT . '/includes/layout/footer.php';
