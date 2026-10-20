<?php
$hasLogo = false;
try {
    $hasLogo = db_connect()->table('branding_assets')->where(['asset_type' => 'logo', 'is_active' => 1])->countAllResults() > 0;
} catch (\Throwable) {
    $hasLogo = false;
}
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
    <div class="auth-card">
        <div class="text-center">
            <?php if ($hasLogo): ?>
                <img src="<?= site_url('brand/logo') ?>" alt="Company logo" class="auth-logo">
            <?php else: ?>
                <div class="auth-brand mb-3">DIGIE R WORLD</div>
            <?php endif ?>
            <div class="small text-muted mb-3">DGR Office — Business ERP</div>
        </div>
        <?= $this->renderSection('main') ?>
    </div>
</main>
<script src="<?= asset('vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>
