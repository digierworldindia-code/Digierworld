<?php
$hasLogo     = empty($noLogo) && brand_logo_state() === 'ok'; // the setup page passes noLogo while the tables do not exist yet
$addressMiss = base_url_mismatch();
?>
<!doctype html>
<html lang="en-IN" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $this->renderSection('title') ?> · DGR Office</title>
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#0B0B0D">
    <link rel="icon" href="<?= asset('img/favicon.png') ?>">
<?= view('partials/theme_head') ?>
</head>
<body>
<main class="auth-wrap">
    <div class="auth-card<?= $this->renderSection('card_class') ?>">
        <div class="text-center">
            <?php if ($hasLogo): ?>
                <img src="<?= site_url('brand/logo') ?>" alt="Company logo" class="auth-logo">
            <?php else: ?>
                <div class="auth-brand mb-3">DIGIE R WORLD</div>
            <?php endif ?>
            <div class="small text-muted mb-3">DGR Office — Business ERP</div>
        </div>
        <?php if ($addressMiss !== null): ?>
            <div class="alert alert-warning small" role="alert">
                <strong>Site address setting does not match.</strong> This page was opened at
                <span class="mono"><?= esc($addressMiss['detected']) ?></span> but <span class="mono">app.baseURL</span> in
                <span class="mono">.env</span> is <span class="mono"><?= esc($addressMiss['configured']) ?></span>.
                Links, styles and the page after sign-in follow app.baseURL, so set
                <span class="mono">app.baseURL = '<?= esc($addressMiss['detected']) ?>'</span> in .env.
            </div>
        <?php endif ?>
        <?= $this->renderSection('main') ?>
    </div>
</main>
<script src="<?= asset('vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>
