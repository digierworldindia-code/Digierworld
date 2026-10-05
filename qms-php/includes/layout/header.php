<?php
/**
 * Page header with top bar and side menu.
 * Set before including: $page_title, optionally $page_styles (list of CSS files in assets/).
 */
defined('QMS') || exit;
require_once QMS_ROOT . '/includes/layout/nav.php';

$qmsUser    = current_user();
$qmsCompany = setting_str('company.name', 'QMS');
$qmsLogo    = setting_str('company.logo') !== '';
$qmsCount   = $qmsUser !== null ? approval_count() : 0;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-header" content="X-CSRF-TOKEN">
    <meta name="csrf-name" content="csrf_qms">
    <meta name="csrf-hash" content="<?= csrf_token() ?>">
    <meta name="base-url" content="<?= e(rtrim(base_url(), '/')) ?>">
    <title><?= e($page_title ?? 'QMS') ?> · <?= e($qmsCompany) ?></title>
    <link rel="icon" href="<?= url('favicon.ico') ?>">
    <link rel="stylesheet" href="<?= asset('bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/qms.css') ?>">
    <?php foreach ($page_styles ?? [] as $qmsStyle): ?><link rel="stylesheet" href="<?= asset($qmsStyle) ?>"><?php endforeach ?>
</head>
<body class="qms">
<a class="visually-hidden-focusable" href="#main">Skip to content</a>

<header class="qms-topbar">
    <button class="btn btn-topbar d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#qmsSidebar" aria-controls="qmsSidebar" aria-label="Open menu">
        <?= qms_icon('bi-list') ?>
    </button>
    <a class="qms-brand" href="<?= url('index.php') ?>">
        <?php if ($qmsLogo): ?><img src="<?= url('logo.php') ?>" alt=""><?php endif ?>
        <span>QMS<small><?= e($qmsCompany) ?></small></span>
    </a>
    <span class="qms-shift-chip d-none d-md-inline-flex"><?= qms_icon('bi-calendar3') ?> <?= e(plant_label()) ?></span>

    <span class="ms-auto qms-net" data-net-status role="status" aria-live="polite"><span class="dot"></span><span class="txt">Online</span></span>

    <?php if ($qmsCount > 0): ?>
        <a class="btn btn-topbar d-none d-sm-inline-flex" href="<?= url('approvals.php') ?>">
            <?= qms_icon('bi-pen') ?> <span class="d-none d-md-inline">Approvals</span> <span class="qms-count"><?= $qmsCount ?></span>
        </a>
    <?php endif ?>

    <?php if ($qmsUser !== null): ?>
        <div class="dropdown">
            <button class="btn btn-topbar dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <?= qms_icon('bi-person-circle') ?> <span class="d-none d-md-inline"><?= e($qmsUser['display_name']) ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><span class="dropdown-item-text small text-muted"><?= e($qmsUser['username']) ?> · <?= e($qmsUser['role_name']) ?></span></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= url('account.php') ?>"><?= qms_icon('bi-key') ?> Change password</a></li>
                <li>
                    <form action="<?= url('logout.php') ?>" method="post">
                        <?= csrf_field() ?>
                        <button class="dropdown-item" type="submit"><?= qms_icon('bi-box-arrow-right') ?> Switch user / log out</button>
                    </form>
                </li>
            </ul>
        </div>
    <?php endif ?>
</header>

<div class="qms-shell">
    <nav class="qms-sidebar offcanvas-lg offcanvas-start" id="qmsSidebar" tabindex="-1" aria-label="Main navigation">
        <div class="offcanvas-header d-lg-none">
            <strong>Menu</strong>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#qmsSidebar" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body d-block">
            <?php foreach (nav_sections() as $qmsSection): ?>
                <div class="qms-nav-section"><?= e($qmsSection['title']) ?></div>
                <div class="qms-nav">
                    <?php foreach ($qmsSection['items'] as $qmsItem): ?>
                        <a href="<?= e($qmsItem['url']) ?>"<?= $qmsItem['active'] ? ' class="active" aria-current="page"' : '' ?>>
                            <?= qms_icon($qmsItem['icon']) ?> <span><?= e($qmsItem['label']) ?></span>
                            <?php if ($qmsItem['badge'] > 0): ?><span class="qms-count"><?= $qmsItem['badge'] ?></span><?php endif ?>
                        </a>
                    <?php endforeach ?>
                </div>
            <?php endforeach ?>
        </div>
    </nav>

    <main id="main" class="qms-main">
        <div class="qms-container">
            <?php require QMS_ROOT . '/includes/layout/flash.php'; ?>
