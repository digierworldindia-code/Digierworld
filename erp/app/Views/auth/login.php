<?= $this->extend(config('Auth')->views['layout']) ?>

<?= $this->section('title') ?>Sign in<?= $this->endSection() ?>

<?= $this->section('main') ?>
<h1 class="text-center mb-3">Sign in</h1>

<?php if (session('error') !== null) : ?>
    <div class="alert alert-danger py-2 small" role="alert"><?= esc(session('error')) ?></div>
<?php elseif (session('errors') !== null) : ?>
    <div class="alert alert-danger py-2 small" role="alert">
        <?php foreach ((array) session('errors') as $error) : ?><?= esc($error) ?><br><?php endforeach ?>
    </div>
<?php endif ?>
<?php if (session('message') !== null) : ?>
    <div class="alert alert-success py-2 small" role="alert"><?= esc(session('message')) ?></div>
<?php endif ?>

<form action="<?= url_to('login') ?>" method="post" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="email">Email</label>
        <input type="email" class="form-control" id="email" name="email" inputmode="email" autocomplete="username" value="<?= esc(old('email') ?? '') ?>" required autofocus>
    </div>
    <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
    </div>
    <?php if (setting('Auth.sessionConfig')['allowRemembering']): ?>
        <div class="form-check mb-3">
            <input type="checkbox" name="remember" id="remember" class="form-check-input" <?= old('remember') ? 'checked' : '' ?>>
            <label class="form-check-label small" for="remember">Keep me signed in on this device</label>
        </div>
    <?php endif ?>
    <button type="submit" class="btn btn-primary w-100">Sign in</button>

    <?php if (setting('Auth.allowMagicLinkLogins')) : ?>
        <p class="text-center small mt-3 mb-0">Forgot password? <a href="<?= url_to('magic-link') ?>">Email me a sign-in link</a></p>
    <?php else: ?>
        <p class="text-center small text-muted mt-3 mb-0">Forgot your password? Ask the owner/administrator to reset it from Users &amp; Roles.</p>
    <?php endif ?>
</form>
<div class="auth-foot">Internal system · authorised users only · activity is logged</div>
<?= $this->endSection() ?>
