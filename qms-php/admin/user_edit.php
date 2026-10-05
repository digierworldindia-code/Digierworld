<?php
/**
 * Create (admin/user_edit.php) or edit (?id=5) a login account.
 * POST action: save, reset (new one-time password), unlock, toggle (enable / disable).
 */
require dirname(__DIR__) . '/includes/init.php';
$user = require_permission('user.manage');
require_once QMS_ROOT . '/includes/users.php';

$id   = get_int('id');
$self = 'admin/user_edit.php' . ($id > 0 ? '?id=' . $id : '');

if (is_post()) {
    try {
        switch (post('action')) {
            case 'reset':
                $password = user_reset_password($id, $user);
                done('Password reset. One-time password (shown only now): ' . $password . ' — the user must change it at next login.', $self);
            case 'unlock':
                user_unlock($id, $user);
                done('Account unlocked.', $self);
            case 'toggle':
                $status = user_toggle_status($id, $user);
                done($status === 'ACTIVE' ? 'Account enabled.' : 'Account disabled. Open sessions were ended.', $self);
            default:
                $input = post_fields(['username', 'email', 'role_id', 'employee_id']);
                if ($id > 0) {
                    user_update($id, $input, $user);
                    done('User saved.', $self);
                }
                $result = user_create($input, $user);
                done('User created. One-time password (give it to the user, shown only now): ' . $result['password'], 'admin/user_edit.php?id=' . $result['id']);
        }
    } catch (Throwable $e) {
        back_with_error($e, $self);
    }
}

$account   = $id > 0 ? user_find($id) : null;
$isNew     = $account === null;
$roles     = user_roles();
$employees = user_linkable_employees($account !== null && $account['employee_id'] !== null ? (int) $account['employee_id'] : null);
$errors    = form_errors();
$locked    = $account !== null && $account['locked_until'] !== null && $account['locked_until'] > now_str();
$val       = static fn (string $k): string => (string) (old($k) ?? ($account[$k] ?? ''));
$button    = static fn (string $action, string $label, string $class, string $confirm = ''): string => '<form method="post" action="' . e(url($self)) . '"'
    . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field() . '<input type="hidden" name="action" value="' . $action . '">'
    . '<button class="' . $class . ' w-100" type="submit">' . $label . '</button></form>';

$page_title = $isNew ? 'New user' : 'User ' . $account['username'];
require QMS_ROOT . '/includes/layout/header.php';
?>
<div class="qms-page-head">
    <div><h1><?= $isNew ? 'New user' : 'User ' . e($account['username']) ?></h1>
        <p class="qms-sub"><?= $isNew ? 'A one-time password is generated; the user must change it at first login.' : 'Changing the role ends the user\'s open sessions.' ?></p></div>
    <a class="btn btn-outline-secondary" href="<?= url('admin/users.php') ?>"><?= qms_icon('bi-arrow-left') ?> Back</a>
</div>
<div class="row g-3">
<div class="col-lg-7">
<div class="card"><div class="card-body">
<form method="post" action="<?= e(url($self)) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="mb-3">
        <label class="form-label required" for="username">Username</label>
        <input class="form-control form-control-lg<?= field_invalid($errors, 'username') ?>" id="username" name="username" value="<?= e($val('username')) ?>"
               maxlength="50" autocomplete="off" <?= $isNew ? 'required' : 'readonly' ?>>
        <?= field_error($errors, 'username') ?>
        <?php if (! $isNew): ?><div class="form-text">Usernames cannot be changed.</div><?php endif ?>
    </div>
    <div class="mb-3">
        <label class="form-label" for="email">E-mail</label>
        <input class="form-control<?= field_invalid($errors, 'email') ?>" id="email" name="email" type="email" maxlength="150" value="<?= e($val('email')) ?>">
        <?= field_error($errors, 'email') ?>
    </div>
    <div class="mb-3">
        <label class="form-label required" for="role_id">Role</label>
        <select class="form-select form-select-lg<?= field_invalid($errors, 'role_id') ?>" id="role_id" name="role_id" required>
            <option value="">Choose…</option>
            <?php foreach ($roles as $role): ?>
                <option value="<?= (int) $role['id'] ?>"<?= $val('role_id') === (string) $role['id'] ? ' selected' : '' ?>><?= e($role['name']) ?></option>
            <?php endforeach ?>
        </select>
        <?= field_error($errors, 'role_id') ?>
    </div>
    <div class="mb-4">
        <label class="form-label" for="employee_id">Employee</label>
        <select class="form-select<?= field_invalid($errors, 'employee_id') ?>" id="employee_id" name="employee_id">
            <option value="">— not linked —</option>
            <?php foreach ($employees as $emp): ?>
                <option value="<?= (int) $emp['id'] ?>"<?= $val('employee_id') === (string) $emp['id'] ? ' selected' : '' ?>><?= e($emp['employee_code'] . ' · ' . $emp['full_name']) ?></option>
            <?php endforeach ?>
        </select>
        <?= field_error($errors, 'employee_id') ?>
        <div class="form-text">Signatures and printed reports show the linked employee's name and code.</div>
    </div>
    <button class="btn btn-primary btn-lg" type="submit" data-once><?= qms_icon('bi-check2') ?> <?= $isNew ? 'Create user' : 'Save' ?></button>
</form>
</div></div>
</div>
<?php if (! $isNew): ?>
<div class="col-lg-5">
<div class="card"><div class="card-header">Account</div><div class="card-body">
    <dl class="qms-dl mb-3">
        <dt>Status</dt><dd><?= e($account['status']) ?></dd>
        <dt>Last login</dt><dd><?= e(plant_dt($account['last_login_at'])) ?: 'Never' ?></dd>
        <dt>Password changed</dt><dd><?= e(plant_dt($account['password_changed_at'])) ?: '—' ?></dd>
        <dt>Locked</dt><dd><?= $locked ? 'Until ' . e(plant_dt($account['locked_until'])) : 'No' ?></dd>
    </dl>
    <div class="d-grid gap-2">
        <?= $button('reset', qms_icon('bi-key') . ' Reset password', 'btn btn-outline-primary', 'Generate a new one-time password for this user?') ?>
        <?php if ($locked): ?><?= $button('unlock', qms_icon('bi-unlock') . ' Unlock account', 'btn btn-outline-primary') ?><?php endif ?>
        <?php if ((int) $account['id'] !== (int) $user['id']): ?>
            <?= $account['status'] === 'ACTIVE'
                ? $button('toggle', qms_icon('bi-person-x') . ' Disable account', 'btn btn-outline-danger', 'Disable this account? Open sessions end immediately.')
                : $button('toggle', qms_icon('bi-person-check') . ' Enable account', 'btn btn-outline-success', 'Enable this account?') ?>
        <?php endif ?>
    </div>
</div></div>
</div>
<?php endif ?>
</div>
<?php require QMS_ROOT . '/includes/layout/footer.php';
