<?php
/**
 * Main application layout.
 *
 * @var \App\Core\View $this
 * @var array<string, mixed>|null $currentUser
 */
$navigation = service('navigation');
$path       = service('request')->getUri()->getPath();
$company    = (string) qms_setting('company.name', 'QMS');
$hasLogo    = (string) qms_setting('company.logo', '') !== '';
$approvals  = $navigation->approvalCount();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-header" content="<?= esc(config('Security')->headerName, 'attr') ?>">
    <meta name="csrf-name" content="<?= esc(csrf_token(), 'attr') ?>">
    <meta name="csrf-hash" content="<?= esc(csrf_hash(), 'attr') ?>">
    <meta name="base-url" content="<?= esc(rtrim(base_url(), '/'), 'attr') ?>">
    <title><?= esc($title ?? 'QMS') ?> · <?= esc($company) ?></title>
    <link rel="icon" href="<?= base_url('favicon.ico') ?>">
    <link rel="stylesheet" href="<?= qms_asset('assets/vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= qms_asset('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= qms_asset('assets/css/qms.css') ?>">
    <?= $this->renderSection('head') ?>
</head>
<body class="qms">
<a class="visually-hidden-focusable" href="#main">Skip to content</a>

<header class="qms-topbar">
    <button class="btn btn-topbar d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#qmsSidebar" aria-controls="qmsSidebar" aria-label="Open menu">
        <?= qms_icon('bi-list') ?>
    </button>
    <a class="qms-brand" href="<?= site_url('/') ?>">
        <?php if ($hasLogo): ?><img src="<?= site_url('media/logo') ?>" alt=""><?php endif ?>
        <span>QMS<small><?= esc($company) ?></small></span>
    </a>
    <span class="qms-shift-chip d-none d-md-inline-flex"><?= qms_icon('bi-calendar3') ?> <?= esc($navigation->plantLabel()) ?></span>

    <span class="ms-auto qms-net" data-net-status role="status" aria-live="polite"><span class="dot"></span><span class="txt">Online</span></span>

    <?php if ($approvals > 0): ?>
        <a class="btn btn-topbar d-none d-sm-inline-flex" href="<?= site_url('approvals') ?>">
            <?= qms_icon('bi-pen') ?> <span class="d-none d-md-inline">Approvals</span> <span class="qms-count"><?= $approvals ?></span>
        </a>
    <?php endif ?>

    <?php if ($currentUser !== null): ?>
        <div class="dropdown">
            <button class="btn btn-topbar dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <?= qms_icon('bi-person-circle') ?> <span class="d-none d-md-inline"><?= esc($currentUser['display_name']) ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><span class="dropdown-item-text small text-muted"><?= esc($currentUser['username']) ?> · <?= esc($currentUser['role_name']) ?></span></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= site_url('account/password') ?>"><?= qms_icon('bi-key') ?> Change password</a></li>
                <li>
                    <form action="<?= site_url('logout') ?>" method="post">
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
            <?php foreach ($navigation->sections($path) as $section): ?>
                <div class="qms-nav-section"><?= esc($section['title']) ?></div>
                <div class="qms-nav">
                    <?php foreach ($section['items'] as $item): ?>
                        <a href="<?= esc($item['url'], 'attr') ?>"<?= $item['active'] ? ' class="active" aria-current="page"' : '' ?>>
                            <?= qms_icon($item['icon']) ?> <span><?= esc($item['label']) ?></span>
                            <?php if ($item['badge'] > 0): ?><span class="qms-count"><?= $item['badge'] ?></span><?php endif ?>
                        </a>
                    <?php endforeach ?>
                </div>
            <?php endforeach ?>
        </div>
    </nav>

    <main id="main" class="qms-main">
        <div class="qms-container">
            <?= view('partials/flash') ?>
            <?= $this->renderSection('content') ?>
        </div>
    </main>
</div>

<script src="<?= qms_asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script type="module" src="<?= qms_asset('assets/js/app.js') ?>"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
