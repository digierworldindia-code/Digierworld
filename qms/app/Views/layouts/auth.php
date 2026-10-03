<?php
/** @var \CodeIgniter\View\View $this */
$company = (string) qms_setting('company.name', 'QMS');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-header" content="<?= esc(config('Security')->headerName, 'attr') ?>">
    <meta name="csrf-name" content="<?= esc(csrf_token(), 'attr') ?>">
    <meta name="csrf-hash" content="<?= esc(csrf_hash(), 'attr') ?>">
    <title><?= esc($title ?? 'Sign in') ?> · <?= esc($company) ?></title>
    <link rel="icon" href="<?= base_url('favicon.ico') ?>">
    <link rel="stylesheet" href="<?= qms_asset('assets/vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= qms_asset('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= qms_asset('assets/css/qms.css') ?>">
</head>
<body class="qms">
<main class="qms-auth">
    <div class="qms-auth-card">
        <div class="brand">
            <?php if ((string) qms_setting('company.logo', '') !== ''): ?>
                <img src="<?= site_url('media/logo') ?>" alt="<?= esc($company, 'attr') ?>">
            <?php endif ?>
            <h1><?= esc($company) ?></h1>
            <p>Quality Inspection System</p>
        </div>
        <?= view('partials/flash') ?>
        <?= $this->renderSection('content') ?>
    </div>
</main>
<script type="module" src="<?= qms_asset('assets/js/app.js') ?>"></script>
</body>
</html>
