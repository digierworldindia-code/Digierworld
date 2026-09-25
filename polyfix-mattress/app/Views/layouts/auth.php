<!doctype html>
<html lang="en-IN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= esc($title ?? 'Sign in') ?> · <?= esc(brand('name')) ?></title>
    <link rel="icon" href="<?= base_url('images/polyfix-logo.svg') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= base_url('assets/css/fonts.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/console.css') ?>">
</head>
<body>
<main class="auth">
    <div class="auth-card">
        <a class="auth-brand" href="<?= site_url('/') ?>"><?= brand_wordmark() ?></a>
        <div class="mt-4"><?= view('partials/console_flash') ?></div>
        <?= $this->renderSection('content') ?>
    </div>
</main>
</body>
</html>
