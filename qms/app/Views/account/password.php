<?php
/**
 * @var bool          $forced
 * @var list<string>  $rules
 * @var array<string, string> $errors
 */
?>
<?= $this->extend($forced ? 'layouts/auth' : 'layouts/app') ?>
<?= $this->section('content') ?>
<?php if (! $forced): ?>
<div class="qms-page-head"><div><h1>Change password</h1></div></div>
<div class="card"><div class="card-body">
<?php else: ?>
<div class="alert alert-warning">You must choose a new password before you continue.</div>
<?php endif ?>
<form method="post" action="<?= site_url('account/password') ?>" class="<?= $forced ? '' : 'col-lg-6' ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label required" for="current_password">Current password</label>
        <input class="form-control form-control-lg<?= field_invalid($errors, 'current_password') ?>" id="current_password" name="current_password" type="password" autocomplete="current-password" required>
        <?= field_error($errors, 'current_password') ?>
    </div>
    <div class="mb-3">
        <label class="form-label required" for="new_password">New password</label>
        <div class="qms-pw-wrap">
            <input class="form-control form-control-lg<?= field_invalid($errors, 'new_password') ?>" id="new_password" name="new_password" type="password" autocomplete="new-password" required aria-describedby="pwRules">
            <button class="btn btn-outline-secondary" type="button" data-toggle-password="#new_password" aria-label="Show password"><?= qms_icon('bi-eye') ?></button>
        </div>
        <?= field_error($errors, 'new_password') ?>
        <ul class="form-text small mt-2 mb-0" id="pwRules">
            <?php foreach ($rules as $rule): ?><li><?= esc($rule) ?></li><?php endforeach ?>
        </ul>
    </div>
    <div class="mb-4">
        <label class="form-label required" for="confirm_password">Repeat new password</label>
        <input class="form-control form-control-lg<?= field_invalid($errors, 'confirm_password') ?>" id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required>
        <?= field_error($errors, 'confirm_password') ?>
    </div>
    <button class="btn btn-primary btn-lg" type="submit" data-once><?= qms_icon('bi-check2') ?> Save new password</button>
</form>
<?php if ($forced): ?>
<form method="post" action="<?= site_url('logout') ?>" class="mt-3 text-center">
    <?= csrf_field() ?>
    <button class="btn btn-link" type="submit">Log out</button>
</form>
<?php else: ?>
</div></div>
<?php endif ?>
<?= $this->endSection() ?>
