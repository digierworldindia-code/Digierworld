<?= $this->extend('layouts/auth') ?>
<?= $this->section('content') ?>
<div class="bc-card"><div class="bc-card-body p-4">
    <h1 class="h4 mb-1">Reset access</h1>
    <p class="text-muted mb-4">Enter your account email. We'll send a one-time sign-in link; after signing in you can set a new password.</p>
    <?php if (session('error') !== null): ?><div class="alert alert-danger" role="alert"><?= esc(session('error')) ?></div><?php endif ?>
    <?php if (session('errors') !== null): ?><div class="alert alert-danger" role="alert"><?= esc(is_array(session('errors')) ? implode(' ', session('errors')) : session('errors')) ?></div><?php endif ?>
    <form action="<?= url_to('magic-link') ?>" method="post">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label" for="email">Email</label>
            <input type="email" class="form-control" id="email" name="email" autocomplete="email" value="<?= esc((string) old('email', auth()->user()->email ?? ''), 'attr') ?>" required></div>
        <button type="submit" class="btn btn-primary w-100">Send sign-in link</button>
    </form>
    <p class="text-center small mt-3 mb-0"><a href="<?= url_to('login') ?>">Back to sign in</a></p>
</div></div>
<?= $this->endSection() ?>
