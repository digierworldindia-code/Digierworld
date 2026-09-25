<?php
/**
 * Admin console layout.
 *
 * @var string $title
 * @var string $nav   path of the current section, e.g. "admin/claims"
 */
$ctx   = service('requestContext');
$user  = $ctx->user();
$nav ??= '';
$initials = implode('', array_map(static fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)), array_slice(preg_split('/\s+/', trim($user['full_name'] ?? '?')), 0, 2)));
$unread   = (int) db_connect()->table('notifications')->where(['user_id' => $ctx->userId(), 'read_at' => null])->countAllResults();
?><!doctype html>
<html lang="en-IN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= esc($title ?? 'Console') ?> · <?= esc(brand('shortName')) ?> Console</title>
    <link rel="icon" href="<?= base_url('images/polyfix-logo.svg') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= base_url('assets/css/fonts.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/console.css') ?>">
</head>
<body>
<a class="skip-link" href="#content">Skip to content</a>
<div class="console">
    <aside class="console-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="sidebar" aria-label="Console navigation">
        <div class="d-flex align-items-center justify-content-between">
            <a class="brand" href="<?= site_url('admin') ?>"><?= brand_wordmark() ?> <small>Console</small></a>
            <button type="button" class="btn-close btn-close-white d-lg-none me-3" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Close menu"></button>
        </div>
        <nav class="offcanvas-body console-nav d-block">
            <?php foreach (config('ConsoleNav')->groups as $group => $items): ?>
                <?php $visible = array_filter($items, static fn ($i) => $ctx->hasRolePermission($i[2])); ?>
                <?php if ($visible === []) continue; ?>
                <div class="nav-group">
                    <div class="nav-group__label"><?= esc($group) ?></div>
                    <?php foreach ($visible as [$label, $path, , $icon]): ?>
                        <?php $current = $nav === $path; ?>
                        <a href="<?= site_url($path) ?>"<?= $current ? ' aria-current="page"' : '' ?>>
                            <i class="bi <?= $icon ?>" aria-hidden="true"></i><?= esc($label) ?>
                            <?php if ($path === 'admin/notifications' && $unread > 0): ?><span class="count"><?= $unread ?></span><?php endif ?>
                        </a>
                    <?php endforeach ?>
                </div>
            <?php endforeach ?>
        </nav>
    </aside>

    <div class="console-main">
        <header class="console-topbar">
            <button class="btn btn-light btn-sm d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Open menu"><i class="bi bi-list"></i></button>
            <?php if ($ctx->hasRolePermission('mattress:read')): ?>
            <form class="search" method="get" action="<?= site_url('admin/lookup') ?>" role="search">
                <input class="form-control form-control-sm" type="search" name="q" placeholder="Serial number, claim or dispatch number" aria-label="Look up a serial, claim or dispatch number" maxlength="40">
            </form>
            <?php endif ?>
            <div class="ms-auto dropdown">
                <button class="btn btn-link text-decoration-none user-chip p-0" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="avatar" aria-hidden="true"><?= esc($initials) ?></span>
                    <span class="d-none d-sm-block text-start lh-sm">
                        <span class="d-block small fw-semibold text-dark"><?= esc($user['full_name'] ?? '') ?></span>
                        <span class="d-block small text-muted"><?= esc(\App\Libraries\Rbac::roleName($ctx->primaryRole() ?? '')) ?></span>
                    </span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="<?= site_url('account/security') ?>"><i class="bi bi-shield-lock me-2"></i>Password &amp; security</a></li>
                    <li><a class="dropdown-item" href="<?= site_url('/') ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-2"></i>View website</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="post" action="<?= site_url('logout') ?>"><?= csrf_field() ?>
                            <button class="dropdown-item" type="submit"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button>
                        </form>
                    </li>
                </ul>
            </div>
        </header>

        <main class="console-content" id="content">
            <?= view('partials/console_flash') ?>
            <?= $this->renderSection('content') ?>
        </main>
        <footer class="console-footer"><?= esc(brand('name')) ?> · <?= esc(brand('location')['line']) ?> · Times shown in IST</footer>
    </div>
</div>
<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>" defer></script>
<script src="<?= base_url('assets/js/console.js') ?>" defer></script>
</body>
</html>
