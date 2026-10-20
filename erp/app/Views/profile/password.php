<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="row"><div class="col-md-7 col-lg-5">
<div class="card"><div class="card-body">
    <form method="post" action="<?= site_url('profile/password') ?>" autocomplete="off">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label required" for="current_password">Current password</label>
            <input type="password" class="form-control<?= is_invalid('current_password') ?>" id="current_password" name="current_password" autocomplete="current-password" required>
            <?= field_error('current_password') ?>
        </div>
        <div class="mb-3">
            <label class="form-label required" for="password">New password</label>
            <input type="password" class="form-control<?= is_invalid('password') ?>" id="password" name="password" autocomplete="new-password" required minlength="<?= (int) config('Auth')->minimumPasswordLength ?>">
            <div class="form-text">At least <?= (int) config('Auth')->minimumPasswordLength ?> characters with letters and numbers; must not contain your name or email.</div>
            <?= field_error('password') ?>
        </div>
        <div class="mb-3">
            <label class="form-label required" for="password_confirm">Confirm new password</label>
            <input type="password" class="form-control<?= is_invalid('password_confirm') ?>" id="password_confirm" name="password_confirm" autocomplete="new-password" required>
            <?= field_error('password_confirm') ?>
        </div>
        <button class="btn btn-primary">Update password</button>
    </form>
</div></div>
</div></div>
<?= $this->endSection() ?>
