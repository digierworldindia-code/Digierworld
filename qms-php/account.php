<?php
/**
 * Change own password (forced after a reset and when the password expired).
 */
require __DIR__ . '/includes/init.php';
$user = require_login();

if (is_post()) {
    try {
        auth_change_password($user['id'], (string) ($_POST['current_password'] ?? ''), (string) ($_POST['new_password'] ?? ''), (string) ($_POST['confirm_password'] ?? ''));
    } catch (Throwable $e) {
        back_with_error($e, 'account.php');
    }
    done('Your password has been changed.', 'index.php');
}

$forced     = auth_must_change_password($user);
$errors     = form_errors();
$page_title = 'Change password';
require QMS_ROOT . '/includes/layout/' . ($forced ? 'auth_header.php' : 'header.php');
?>
<?php if (! $forced): ?>
<div class="qms-page-head"><div><h1>Change password</h1></div></div>
<div class="card"><div class="card-body">
<?php else: ?>
<div class="alert alert-warning">You must choose a new password before you continue.</div>
<?php endif ?>
<form method="post" action="<?= url('account.php') ?>" class="<?= $forced ? '' : 'col-lg-6' ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label required" for="current_password">Current password</label>
        <input class="form-control form-control-lg<?= field_invalid($errors, 'current_password') ?>" id="current_password" name="current_password" type="password" autocomplete="current-password" required>
        <?= field_error($errors, 'current_password') ?>
    </div>
    <div class="mb-3">
        <label class="form-label required" for="new_password">New password</label>
        <div class="qms-pw-wrap">
            <input class="form-control form-control-lg<?= field_invalid($errors, 'new_password') ?>" id="new_password" name="new_password" type="password" autocomplete="new-password" maxlength="128" required>
            <button class="btn btn-outline-secondary" type="button" data-toggle-password="#new_password" aria-label="Show password"><?= qms_icon('bi-eye') ?></button>
        </div>
        <?= field_error($errors, 'new_password') ?>
        <ul class="form-text mb-0">
            <?php foreach (password_rules() as $rule): ?><li><?= e($rule) ?></li><?php endforeach ?>
        </ul>
    </div>
    <div class="mb-4">
        <label class="form-label required" for="confirm_password">Repeat new password</label>
        <input class="form-control form-control-lg<?= field_invalid($errors, 'confirm_password') ?>" id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" maxlength="128" required>
        <?= field_error($errors, 'confirm_password') ?>
    </div>
    <button class="btn btn-primary btn-lg" type="submit" data-once><?= qms_icon('bi-check2') ?> Change password</button>
</form>
<?php if (! $forced): ?></div></div><?php endif ?>
<?php if ($forced): ?>
    <form method="post" action="<?= url('logout.php') ?>" class="mt-3 text-center"><?= csrf_field() ?><button class="btn btn-link" type="submit">Log out</button></form>
<?php endif ?>
<?php require QMS_ROOT . '/includes/layout/' . ($forced ? 'auth_footer.php' : 'footer.php');
