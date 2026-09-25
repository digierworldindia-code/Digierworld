<?= $this->extend('layouts/auth') ?>
<?= $this->section('content') ?>
<?php /** @var string $token */ ?>
<h1 class="h4 mb-1">Choose a new password</h1>
<?php if ($token === ''): ?>
<div class="alert alert-danger mt-3">That reset link is incomplete or no longer valid. <a href="<?= site_url('forgot-password') ?>">Request a new one</a>.</div>
<?php else: ?>
<p class="text-muted small mb-4">At least 12 characters with upper and lower case letters, a digit and a symbol. Every other signed-in session will be signed out.</p>
<form method="post" action="<?= site_url('reset-password') ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= esc($token, 'attr') ?>">
    <div class="mb-3">
        <label class="form-label" for="password">New password</label>
        <input class="form-control form-control-lg" id="password" name="password" type="password" autocomplete="new-password" required minlength="12" maxlength="200">
    </div>
    <div class="mb-3">
        <label class="form-label" for="password_confirm">Repeat it</label>
        <input class="form-control form-control-lg" id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required minlength="12" maxlength="200">
    </div>
    <button class="btn btn-primary btn-lg w-100" type="submit">Set password</button>
</form>
<?php endif ?>
<?= $this->endSection() ?>
