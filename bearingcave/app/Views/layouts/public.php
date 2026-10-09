<!doctype html>
<html lang="en">
<head>
<?= view('components/head', get_defined_vars()) ?>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<?php
$user      = auth()->user();
$company   = $user ? current_company() : null;
$isBuyer   = ($company['company_type'] ?? null) === 'buyer';
$cartCount = 0;
if ($isBuyer) {
    $cart = db_connect()->table('carts')->where('user_id', $user->id)->where('status', 'active')->get()->getRowArray();
    $cartCount = $cart ? db_connect()->table('cart_items')->where('cart_id', $cart['id'])->countAllResults() : 0;
}
$unread = $user ? service('notifications')->unreadCount((int) $user->id) : 0;
?>
<div class="topbar d-none d-md-block">
    <div class="container d-flex justify-content-between py-1">
        <span><i class="bi bi-shield-check me-1" aria-hidden="true"></i>Verified suppliers · Confidential listings · Optional inspection</span>
        <span class="d-flex gap-3">
            <a href="<?= site_url('for-suppliers') ?>">Sell surplus stock</a>
            <a href="<?= site_url('trust-and-verification') ?>">Trust &amp; verification</a>
            <a href="<?= site_url('contact') ?>">Contact</a>
        </span>
    </div>
</div>
<header class="site-header">
    <nav class="navbar navbar-expand-lg py-2" aria-label="Main">
        <div class="container gap-2">
            <a class="navbar-brand" href="<?= site_url('/') ?>"><span class="brand-mark" aria-hidden="true"><i class="bi bi-gear-wide-connected"></i></span>BearingCave</a>
            <form class="header-search flex-grow-1 d-none d-lg-flex position-relative" action="<?= site_url('marketplace') ?>" method="get" role="search">
                <div class="input-group">
                    <label class="visually-hidden" for="hdrSearch">Search parts</label>
                    <input id="hdrSearch" class="form-control" type="search" name="q" value="<?= esc((string) service('request')->getGet('q')) ?>" placeholder="Part number, OEM number, brand or product" data-autocomplete="<?= site_url('search/suggest') ?>">
                    <button class="btn btn-primary" type="submit" aria-label="Search"><i class="bi bi-search" aria-hidden="true"></i></button>
                </div>
            </form>
            <div class="d-flex align-items-center gap-1 ms-auto order-lg-3">
                <?php if ($user): ?>
                    <a class="icon-btn" href="<?= site_url('notifications') ?>" aria-label="Notifications (<?= $unread ?> unread)"><i class="bi bi-bell" aria-hidden="true"></i><?php if ($unread): ?><span class="count"><?= $unread > 99 ? '99+' : $unread ?></span><?php endif ?></a>
                    <?php if ($isBuyer): ?><a class="icon-btn" href="<?= site_url('buyer/cart') ?>" aria-label="Cart (<?= $cartCount ?> items)"><i class="bi bi-cart3" aria-hidden="true"></i><?php if ($cartCount): ?><span class="count"><?= $cartCount ?></span><?php endif ?></a><?php endif ?>
                    <a class="btn btn-navy btn-sm ms-1" href="<?= site_url('dashboard') ?>"><i class="bi bi-grid-1x2 me-1" aria-hidden="true"></i>Dashboard</a>
                <?php else: ?>
                    <a class="btn btn-link btn-sm text-decoration-none fw-semibold" href="<?= site_url('login') ?>">Sign in</a>
                    <a class="btn btn-primary btn-sm" href="<?= site_url('register') ?>">Register free</a>
                <?php endif ?>
                <button class="navbar-toggler border-0 ms-1" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
            </div>
            <div class="collapse navbar-collapse order-lg-2 flex-grow-0" id="mainNav">
                <form class="d-lg-none my-2 position-relative" action="<?= site_url('marketplace') ?>" method="get" role="search">
                    <label class="visually-hidden" for="mSearch">Search parts</label>
                    <input id="mSearch" class="form-control" type="search" name="q" placeholder="Search part number or product" data-autocomplete="<?= site_url('search/suggest') ?>">
                </form>
                <ul class="navbar-nav ms-lg-3">
                    <li class="nav-item"><a class="nav-link<?= nav_active('marketplace') ? ' active' : '' ?>" href="<?= site_url('marketplace') ?>">Marketplace</a></li>
                    <li class="nav-item"><a class="nav-link<?= nav_active('suppliers') ? ' active' : '' ?>" href="<?= site_url('suppliers') ?>">Suppliers</a></li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Services</a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="<?= site_url('services/inspection') ?>"><i class="bi bi-clipboard-check me-2" aria-hidden="true"></i>Inspection</a></li>
                            <li><a class="dropdown-item" href="<?= site_url('services/logistics') ?>"><i class="bi bi-truck me-2" aria-hidden="true"></i>Logistics</a></li>
                            <li><a class="dropdown-item" href="<?= site_url('how-it-works') ?>"><i class="bi bi-diagram-3 me-2" aria-hidden="true"></i>How it works</a></li>
                        </ul>
                    </li>
                    <li class="nav-item"><a class="nav-link<?= nav_active('membership') ? ' active' : '' ?>" href="<?= site_url('membership') ?>">Membership</a></li>
                </ul>
            </div>
        </div>
    </nav>
</header>
<main id="main">
    <?php if (session()->getFlashdata('success') || session()->getFlashdata('error') || session()->getFlashdata('message')): ?>
        <div class="container pt-3"><?= view('components/flash') ?></div>
    <?php endif ?>
    <?= $this->renderSection('content') ?>
</main>
<footer class="site-footer mt-5">
    <div class="container py-5">
        <div class="row g-4">
            <div class="col-lg-4">
                <a class="d-inline-flex align-items-center gap-2 text-white fw-bold fs-5 mb-2" href="<?= site_url('/') ?>"><span class="brand-mark" style="background:#1f5fbf" aria-hidden="true"><i class="bi bi-gear-wide-connected"></i></span>BearingCave</a>
                <p class="mb-2">The global B2B marketplace for genuine surplus, obsolete and slow-moving automotive inventory.</p>
                <p class="small mb-0 opacity-75">Brand names are used to describe listed products only. BearingCave is not affiliated with listed manufacturers unless stated.</p>
            </div>
            <div class="col-6 col-lg-2"><h6>Marketplace</h6><ul class="list-unstyled d-grid gap-2"><li><a href="<?= site_url('marketplace') ?>">Search inventory</a></li><li><a href="<?= site_url('suppliers') ?>">Verified suppliers</a></li><li><a href="<?= site_url('compare') ?>">Compare</a></li></ul></div>
            <div class="col-6 col-lg-2"><h6>Sell</h6><ul class="list-unstyled d-grid gap-2"><li><a href="<?= site_url('for-suppliers') ?>">For suppliers</a></li><li><a href="<?= site_url('membership') ?>">Membership</a></li><li><a href="<?= site_url('register/supplier') ?>">List surplus stock</a></li></ul></div>
            <div class="col-6 col-lg-2"><h6>Buy</h6><ul class="list-unstyled d-grid gap-2"><li><a href="<?= site_url('for-buyers') ?>">For buyers</a></li><li><a href="<?= site_url('services/inspection') ?>">Inspection</a></li><li><a href="<?= site_url('services/logistics') ?>">Logistics</a></li></ul></div>
            <div class="col-6 col-lg-2"><h6>Company</h6><ul class="list-unstyled d-grid gap-2"><li><a href="<?= site_url('about') ?>">About</a></li><li><a href="<?= site_url('trust-and-verification') ?>">Trust &amp; verification</a></li><li><a href="<?= site_url('contact') ?>">Contact</a></li>
                <?php foreach (db_connect()->table('cms_pages')->select('slug, title')->where('status', 'published')->where('show_in_footer', 1)->get()->getResultArray() as $p): ?><li><a href="<?= site_url('page/' . $p['slug']) ?>"><?= esc($p['title']) ?></a></li><?php endforeach ?></ul></div>
        </div>
        <hr class="border-secondary-subtle opacity-25 my-4">
        <div class="d-flex flex-wrap justify-content-between gap-2 small"><span>&copy; <?= date('Y') ?> BearingCave. All rights reserved.</span><span>www.bearingcave.com</span></div>
    </div>
</footer>
<script src="<?= base_url('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('assets/js/app.js') ?>?v=1"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
