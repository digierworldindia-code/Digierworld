<?php defined('QMS') || exit; ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($page_title) ?></title>
    <link rel="stylesheet" href="<?= asset('bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/qms.css') ?>">
</head>
<body class="qms">
<main class="container py-4 qms-setup">
    <div class="d-flex align-items-center gap-2 mb-3">
        <?= qms_icon('bi-clipboard-check', 'fs-3 text-primary') ?>
        <h1 class="h3 mb-0">QMS · Quality Inspection System</h1>
    </div>
