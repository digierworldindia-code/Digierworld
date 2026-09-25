<?= $this->extend('layouts/auth') ?>
<?= $this->section('content') ?>
<h1 class="h4 mb-1">Reset your password</h1>
<p class="text-muted small mb-4">Enter the email address on your account and we will send a link to choose a new password.</p>
<form method="post" action="<?= site_url('forgot-password') ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="email">Email</label>
        <input class="form-control form-control-lg" id="email" name="email" type="email" autocomplete="username" required maxlength="255">
    </div>
    <button class="btn btn-primary btn-lg w-100" type="submit">Send reset link</button>
</form>
<p class="small mt-4 mb-0"><a href="<?= site_url('admin/login') ?>">Back to sign-in</a></p>
<?= $this->endSection() ?>
