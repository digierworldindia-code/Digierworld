<?php
/**
 * Small centred layout for the login and forced password pages.
 */
defined('QMS') || exit;
$qmsCompany = setting_str('company.name', 'QMS');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-header" content="X-CSRF-TOKEN">
    <meta name="csrf-name" content="csrf_qms">
    <meta name="csrf-hash" content="<?= csrf_token() ?>">
    <title><?= e($page_title ?? 'Sign in') ?> · <?= e($qmsCompany) ?></title>
    <link rel="icon" href="<?= url('favicon.ico') ?>">
    <link rel="stylesheet" href="<?= asset('bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/qms.css') ?>">
</head>
<body class="qms">
<main class="qms-auth">
    <div class="qms-auth-card">
        <div class="brand">
            <?php if (setting_str('company.logo') !== ''): ?>
                <img src="<?= url('logo.php') ?>" alt="<?= e($qmsCompany) ?>">
            <?php endif ?>
            <h1><?= e($qmsCompany) ?></h1>
            <p>Quality Inspection System</p>
        </div>
        <?php require QMS_ROOT . '/includes/layout/flash.php'; ?>
