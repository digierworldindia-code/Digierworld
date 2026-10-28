<?php
/**
 * First-time administrator setup, step 0: prove access to the server's files.
 *
 * @var string|null $problem
 */
?>
<?= $this->extend('auth/layout') ?>

<?= $this->section('title') ?>Administrator setup<?= $this->endSection() ?>

<?= $this->section('main') ?>
<h1 class="text-center mb-2">Administrator setup</h1>
<p class="small text-muted text-center">No administrator account exists yet. Create it here — no SSH needed.</p>

<?php if ($problem !== null) : ?>
    <div class="alert alert-danger py-2 small" role="alert"><?= esc($problem) ?></div>
<?php endif ?>
<?php if (session('error') !== null) : ?>
    <div class="alert alert-danger py-2 small" role="alert"><?= esc(session('error')) ?></div>
<?php endif ?>

<p class="small mb-1">To prove you manage this server:</p>
<ol class="small ps-3">
    <li>Open your hosting <strong>File Manager</strong> and go to the DGR Office folder (the one that contains <span class="mono">spark</span> and <span class="mono">.env</span>).</li>
    <li>Open <span class="mono">writable/SETUP-CODE.txt</span> and copy the code.</li>
</ol>

<form action="<?= site_url('setup/unlock') ?>" method="post" autocomplete="off">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="code">Setup code</label>
        <input class="form-control mono text-uppercase" id="code" name="code" placeholder="XXXX-XXXX-XXXX" maxlength="20" required autofocus>
    </div>
    <button type="submit" class="btn btn-primary w-100">Continue</button>
</form>
<p class="small text-muted mt-3 mb-0">The code is valid for two hours; after that a new one is written to the same file. This page disappears for good once the administrator account exists.</p>
<?= $this->endSection() ?>
