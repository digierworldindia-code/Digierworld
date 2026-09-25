<?= $this->extend('layouts/auth') ?>
<?= $this->section('content') ?>
<h1 class="h4 mb-1">Two-factor check</h1>
<p class="text-muted small mb-4">Enter the 6-digit code from your authenticator app. Lost your phone? Use one of your recovery codes instead.</p>
<form method="post" action="<?= site_url('login/verify') ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="code">Code</label>
        <input class="form-control form-control-lg mono text-center" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="20" autofocus>
    </div>
    <button class="btn btn-primary btn-lg w-100" type="submit">Verify</button>
</form>
<p class="small mt-4 mb-0"><a href="<?= site_url('admin/login') ?>">Start again</a></p>
<?= $this->endSection() ?>
