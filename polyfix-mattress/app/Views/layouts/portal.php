<?php
/**
 * Dealer portal layout — mobile first. The primary action is a scan, usually
 * one-handed in a showroom, so the main destinations sit in a bottom tab bar.
 *
 * @var string $title
 * @var string $nav  current tab: home|scan|inventory|sell|claims
 */
$ctx  = service('requestContext');
$user = $ctx->user();
$nav ??= '';
$tabs = [
    'home'      => ['dealer', 'Home', 'bi-house'],
    'inventory' => ['dealer/inventory', 'Stock', 'bi-box-seam'],
    'scan'      => ['dealer/scan', 'Scan', 'bi-qr-code-scan'],
    'sell'      => ['dealer/sell', 'Sell', 'bi-receipt'],
    'claims'    => ['dealer/claims', 'Claims', 'bi-clipboard2-pulse'],
];
?><!doctype html>
<html lang="en-IN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#ffffff">
    <title><?= esc($title ?? 'Dealer portal') ?> · <?= esc(brand('shortName')) ?> Dealer</title>
    <link rel="icon" href="<?= base_url('images/polyfix-logo.svg') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= base_url('assets/css/fonts.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/console.css') ?>">
</head>
<body>
<a class="skip-link" href="#content">Skip to content</a>
<header class="portal-top">
    <div class="inner">
        <a class="auth-brand" href="<?= site_url('dealer') ?>"><?= brand_wordmark() ?></a>
        <div class="dropdown">
            <button class="btn btn-light btn-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person-circle me-1" aria-hidden="true"></i><span class="d-none d-sm-inline"><?= esc($user['dealer_name'] ?? '') ?></span><span class="visually-hidden">Account</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><h6 class="dropdown-header"><?= esc($user['full_name'] ?? '') ?><br><span class="fw-normal"><?= esc($user['dealer_name'] ?? '') ?></span></h6></li>
                <li><a class="dropdown-item" href="<?= site_url('dealer/incoming') ?>"><i class="bi bi-truck me-2"></i>Incoming stock</a></li>
                <li><a class="dropdown-item" href="<?= site_url('dealer/sales') ?>"><i class="bi bi-list-check me-2"></i>My sales</a></li>
                <li><a class="dropdown-item" href="<?= site_url('dealer/notifications') ?>"><i class="bi bi-bell me-2"></i>Notifications</a></li>
                <li><a class="dropdown-item" href="<?= site_url('account/security') ?>"><i class="bi bi-shield-lock me-2"></i>Password &amp; security</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><form method="post" action="<?= site_url('logout') ?>"><?= csrf_field() ?><button class="dropdown-item" type="submit"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button></form></li>
            </ul>
        </div>
    </div>
</header>
<main class="portal" id="content">
    <?= view('partials/console_flash') ?>
    <?= $this->renderSection('content') ?>
</main>
<nav class="portal-tabs" aria-label="Dealer portal">
    <?php foreach ($tabs as $key => [$path, $label, $icon]): ?>
    <a href="<?= site_url($path) ?>" class="<?= $key === 'scan' ? 'scan' : '' ?>"<?= $nav === $key ? ' aria-current="page"' : '' ?>><i class="bi <?= $icon ?>" aria-hidden="true"></i><?= esc($label) ?></a>
    <?php endforeach ?>
</nav>
<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>" defer></script>
<script src="<?= base_url('assets/js/console.js') ?>" defer></script>
</body>
</html>
