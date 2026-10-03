<?= $this->extend('layouts/auth') ?>
<?= $this->section('content') ?>
<form method="post" action="<?= site_url('login') ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="username">Username</label>
        <input class="form-control form-control-lg" id="username" name="username" type="text" autocomplete="username"
               autocapitalize="none" spellcheck="false" maxlength="50" required autofocus value="<?= esc(old('username') ?? '', 'attr') ?>">
    </div>
    <div class="mb-4">
        <label class="form-label" for="password">Password</label>
        <div class="qms-pw-wrap">
            <input class="form-control form-control-lg" id="password" name="password" type="password" autocomplete="current-password" maxlength="200" required>
            <button class="btn btn-outline-secondary" type="button" data-toggle-password="#password" aria-label="Show password"><?= qms_icon('bi-eye') ?></button>
        </div>
    </div>
    <button class="btn btn-primary btn-lg w-100" type="submit" data-once><?= qms_icon('bi-box-arrow-in-right') ?> Sign in</button>
    <p class="text-muted small mt-3 mb-0 text-center">Forgot your password? Ask your QMS administrator to reset it.</p>
</form>
<?= $this->endSection() ?>
