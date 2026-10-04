<?php
/**
 * Layout of the one-time setup pages (no database, no session yet).
 *
 * @var \App\Core\View $this
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= esc($title ?? 'QMS setup') ?></title>
    <link rel="stylesheet" href="<?= qms_asset('assets/vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= qms_asset('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= qms_asset('assets/css/qms.css') ?>">
</head>
<body class="qms">
<main class="container py-4 qms-setup">
    <div class="d-flex align-items-center gap-2 mb-3">
        <?= qms_icon('bi-clipboard-check', 'fs-3 text-primary') ?>
        <h1 class="h3 mb-0">QMS · Quality Inspection System</h1>
    </div>
    <?= $this->renderSection('content') ?>
</main>
<script type="module" src="<?= qms_asset('assets/js/app.js') ?>"></script>
</body>
</html>
