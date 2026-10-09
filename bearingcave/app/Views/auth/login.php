<?= $this->extend('layouts/auth') ?>
<?= $this->section('content') ?>
<?php $title = 'Sign in'; ?>
<div class="bc-card">
    <div class="bc-card-body p-4">
        <h1 class="h4 mb-1">Sign in</h1>
        <p class="text-muted mb-4">Access your BearingCave buyer, supplier or staff workspace.</p>
        <?php if (session('errors') !== null && ! is_array(session('errors'))): ?>
            <div class="alert alert-danger" role="alert"><?= esc(session('errors')) ?></div>
        <?php endif ?>
        <form action="<?= url_to('login') ?>" method="post" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="email">Work email</label>
                <input type="email" class="form-control" id="email" name="email" inputmode="email" autocomplete="email" value="<?= esc((string) old('email'), 'attr') ?>" required autofocus>
            </div>
            <div class="mb-3">
                <div class="d-flex justify-content-between"><label class="form-label" for="password">Password</label>
                <?php if (setting('Auth.allowMagicLinkLogins')): ?><a class="small" href="<?= url_to('magic-link') ?>">Forgot password?</a><?php endif ?></div>
                <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
            </div>
            <?php if (setting('Auth.sessionConfig')['allowRemembering']): ?>
                <div class="form-check mb-3">
                    <input type="checkbox" name="remember" class="form-check-input" id="remember"<?= old('remember') ? ' checked' : '' ?>>
                    <label class="form-check-label" for="remember">Keep me signed in on this device</label>
                </div>
            <?php endif ?>
            <button type="submit" class="btn btn-primary w-100">Sign in</button>
        </form>
        <hr class="my-4">
        <p class="text-center mb-0 small">New to BearingCave? <a href="<?= site_url('register') ?>">Create a free business account</a></p>
    </div>
</div>
<p class="text-center small text-muted mt-3">Repeated failed attempts are slowed down for a minute — your account is never locked permanently.</p>
<?= $this->endSection() ?>
