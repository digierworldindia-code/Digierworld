<?= $this->extend('layouts/auth') ?>
<?= $this->section('content') ?>
<?php /** @var string $area admin|dealer */ ?>
<h1 class="h4 mb-1"><?= $area === 'dealer' ? 'Dealer sign-in' : 'Console sign-in' ?></h1>
<p class="text-muted small mb-4"><?= $area === 'dealer' ? 'For appointed ' . esc(brand('shortName')) . ' dealers.' : 'For ' . esc(brand('name')) . ' staff.' ?></p>
<form method="post" action="<?= site_url($area . '/login') ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="email">Email</label>
        <input class="form-control form-control-lg" id="email" name="email" type="email" autocomplete="username" required maxlength="255" autofocus value="<?= esc(old('email', ''), 'attr') ?>">
    </div>
    <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <input class="form-control form-control-lg" id="password" name="password" type="password" autocomplete="current-password" required maxlength="200">
    </div>
    <button class="btn btn-primary btn-lg w-100" type="submit">Sign in</button>
</form>
<p class="small mt-4 mb-0 d-flex justify-content-between flex-wrap gap-2">
    <a href="<?= site_url('forgot-password') ?>">Forgot your password?</a>
    <?php if ($area === 'dealer'): ?><a href="<?= site_url('admin/login') ?>">Staff sign-in</a><?php else: ?><a href="<?= site_url('dealer/login') ?>">Dealer sign-in</a><?php endif ?>
</p>
<?= $this->endSection() ?>
