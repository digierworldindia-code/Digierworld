<!doctype html>
<html lang="en">
<head>
<?php $robots = 'noindex,nofollow'; ?>
<?= view('components/head', get_defined_vars()) ?>
</head>
<body class="app-body">
<a class="skip-link" href="#main">Skip to content</a>
<?php
$area    = $area ?? 'buyer';
$user    = auth()->user();
$company = current_company();
$unread  = service('notifications')->unreadCount((int) $user->id);
$menu    = dashboard_menu($area);
$areaLabel = ['buyer' => 'Buyer workspace', 'supplier' => 'Supplier workspace', 'admin' => 'BearingCave Admin'][$area] ?? '';
?>
<div class="app-shell">
    <aside class="app-sidebar" id="appSidebar" aria-label="<?= esc($areaLabel, 'attr') ?> navigation">
        <a class="brand" href="<?= site_url('/') ?>"><span class="brand-mark" aria-hidden="true"><i class="bi bi-gear-wide-connected"></i></span>BearingCave</a>
        <?php if ($company && $area !== 'admin'): ?>
            <div class="px-3 pt-3 pb-1 small">
                <div class="text-white fw-semibold text-truncate" title="<?= esc($company['legal_name'], 'attr') ?>"><?= esc($company['legal_name']) ?></div>
                <div class="d-flex gap-1 mt-1 flex-wrap"><?= verified_badge($company) ?: status_badge($company['verification_status']) ?> <?= sample_badge($company) ?></div>
            </div>
        <?php endif ?>
        <nav>
            <?php foreach ($menu as $section => $items): ?>
                <div class="area-label"><?= esc($section) ?></div>
                <?php foreach ($items as $it): ?>
                    <?php $locked = ! empty($it[3]) && $area !== 'admin'; $href = $locked ? ($area === 'supplier' ? 'supplier/membership' : 'buyer/verification') : $it[1]; ?>
                    <a class="nav-link<?= nav_active($it[1]) ? ' active' : '' ?>" href="<?= site_url($href) ?>"<?= nav_active($it[1]) ? ' aria-current="page"' : '' ?>>
                        <i class="bi <?= esc($it[2], 'attr') ?>" aria-hidden="true"></i><span><?= esc($it[0]) ?></span>
                        <?php if ($locked): ?><i class="bi bi-lock-fill lock" title="Not included in your plan" aria-label="Locked"></i><?php endif ?>
                    </a>
                <?php endforeach ?>
            <?php endforeach ?>
        </nav>
        <div class="p-3 small opacity-75"><?= esc($areaLabel) ?></div>
    </aside>
    <div class="app-main">
        <header class="app-topbar">
            <button class="btn btn-light d-lg-none" type="button" data-sidebar-toggle aria-controls="appSidebar" aria-expanded="false" aria-label="Open menu"><i class="bi bi-list" aria-hidden="true"></i></button>
            <?php if ($area !== 'admin'): ?>
            <form class="position-relative flex-grow-1 d-none d-md-block" style="max-width:460px" action="<?= site_url('marketplace') ?>" method="get" role="search">
                <label class="visually-hidden" for="dashSearch">Search marketplace</label>
                <input id="dashSearch" class="form-control form-control-sm" type="search" name="q" placeholder="Search part number, OEM or brand" data-autocomplete="<?= site_url('search/suggest') ?>">
            </form>
            <?php else: ?>
            <span class="fw-semibold text-navy d-none d-md-inline"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Admin console</span>
            <?php endif ?>
            <div class="ms-auto d-flex align-items-center gap-1">
                <a class="icon-btn" href="<?= site_url('notifications') ?>" aria-label="Notifications (<?= $unread ?> unread)"><i class="bi bi-bell" aria-hidden="true"></i><?php if ($unread): ?><span class="count"><?= $unread > 99 ? '99+' : $unread ?></span><?php endif ?></a>
                <div class="dropdown">
                    <button class="btn btn-light btn-sm dropdown-toggle d-flex align-items-center gap-2" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle" aria-hidden="true"></i><span class="d-none d-sm-inline text-truncate" style="max-width:180px"><?= esc($user->email) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?= site_url('account') ?>"><i class="bi bi-gear me-2" aria-hidden="true"></i>Account settings</a></li>
                        <li><a class="dropdown-item" href="<?= site_url('/') ?>"><i class="bi bi-house me-2" aria-hidden="true"></i>Public website</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= site_url('logout') ?>"><i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Sign out</a></li>
                    </ul>
                </div>
            </div>
        </header>
        <main id="main" class="app-content">
            <?= view('components/flash') ?>
            <?= $this->renderSection('content') ?>
        </main>
        <footer class="px-4 py-3 small text-muted border-top bg-white d-flex flex-wrap justify-content-between gap-2">
            <span>&copy; <?= date('Y') ?> BearingCave</span>
            <span><a href="<?= site_url('page/terms') ?>">Terms</a> · <a href="<?= site_url('page/privacy') ?>">Privacy</a> · <a href="<?= site_url('contact') ?>">Support</a></span>
        </footer>
    </div>
</div>
<script src="<?= base_url('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('assets/js/app.js') ?>?v=1"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
