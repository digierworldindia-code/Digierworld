<?php
/**
 * Main authenticated layout.
 *
 * @var string $pageTitle
 * @var list<array{0: string, 1?: string}> $breadcrumbs
 */
$menu    = \App\Libraries\MenuBuilder::build();
$uid     = current_user_id();
$unread  = $uid ? service('notifier')->unreadCount($uid) : 0;
$company = db_connect()->table('company_settings')->select('trade_name, legal_name, brand_primary, brand_accent')->where('id', 1)->get()->getRowArray() ?? [];
$hasLogo = db_connect()->table('branding_assets')->where(['asset_type' => 'logo', 'is_active' => 1])->countAllResults() > 0;
$brandName = $company['trade_name'] ?? '' ?: ($company['legal_name'] ?? '' ?: 'DIGIE R WORLD');
$primary = preg_match('/^#[0-9a-fA-F]{6}$/', $company['brand_primary'] ?? '') ? $company['brand_primary'] : '#111111';
$accent  = preg_match('/^#[0-9a-fA-F]{6}$/', $company['brand_accent'] ?? '') ? $company['brand_accent'] : '#B08D57';
$quick = [];
if (can(['billing.invoice.create_draft'])) { $quick[] = ['Tax Invoice', 'billing/documents/new?type=tax_invoice', 'file-earmark-text']; }
if (can(['billing.cash_memo.create_draft'])) { $quick[] = ['Cash Memo', 'billing/documents/new?type=cash_memo', 'cash-coin']; }
if (can(['finance.receipt.create'])) { $quick[] = ['Receipt', 'finance/receipts/new', 'receipt-cutoff']; }
if (can(['finance.transfer.create'])) { $quick[] = ['Cash → Bank Deposit', 'finance/transfers/new', 'arrow-left-right']; }
if (can(['finance.expense.create'])) { $quick[] = ['Expense', 'finance/expenses/new', 'bag']; }
if (can(['contacts.create'])) { $quick[] = ['Client / Vendor', 'contacts/new', 'person-plus']; }
if (can(['crm.lead.create'])) { $quick[] = ['Lead', 'crm/leads/new', 'person-lines-fill']; }
if (can(['crm.task.create'])) { $quick[] = ['Task', 'crm/tasks#new', 'list-check']; }
if (can(['hr.candidate.create'])) { $quick[] = ['Applicant', 'hr/candidates/new', 'person-badge']; }
?>
<!doctype html>
<html lang="en-IN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-header" content="<?= csrf_header() ?>">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <meta name="csrf-field" content="<?= csrf_token() ?>">
    <meta name="base-url" content="<?= esc(rtrim(site_url('/'), '/')) ?>">
    <title><?= esc($pageTitle) ?> · DGR Office</title>
    <link rel="icon" href="<?= base_url('assets/img/favicon.png') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
    <style>:root{--dgr-primary:<?= $primary /* validated #RRGGBB */ ?>;--dgr-accent:<?= $accent /* validated #RRGGBB */ ?>;}</style>
    <?= $this->renderSection('head') ?>
</head>
<body>
<a class="visually-hidden-focusable skip-link" href="#main">Skip to content</a>
<div class="app-shell">
    <aside class="app-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="sidebar" aria-label="Main navigation">
        <div class="sidebar-brand">
            <a href="<?= site_url('/') ?>" class="d-flex align-items-center text-decoration-none">
                <?php if ($hasLogo): ?>
                    <span class="brand-logo-wrap"><img src="<?= site_url('brand/logo') ?>" alt="<?= esc($brandName) ?> logo" class="brand-logo"></span>
                <?php else: ?>
                    <span class="brand-text"><?= esc($brandName) ?></span>
                <?php endif ?>
            </a>
            <button type="button" class="btn-close btn-close-white d-lg-none ms-auto" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Close menu"></button>
        </div>
        <div class="sidebar-search px-3 pb-2">
            <input type="search" class="form-control form-control-sm" placeholder="Filter menu…" data-menu-filter aria-label="Filter menu">
        </div>
        <nav class="sidebar-nav">
            <?php foreach ($menu as $i => $section): ?>
                <?php if (count($section['items']) === 1 && $section['items'][0]['label'] === $section['label']): $item = $section['items'][0]; ?>
                    <a href="<?= esc($item['href']) ?>" class="nav-link-single <?= $item['active'] ? 'active' : '' ?>" data-menu-item>
                        <i class="bi bi-<?= esc($item['icon'] ?? $section['icon']) ?>"></i><span><?= esc($item['label']) ?></span>
                    </a>
                <?php else: ?>
                <div class="nav-section" data-menu-section>
                    <button class="nav-section-toggle <?= $section['active'] ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#navsec<?= $i ?>" aria-expanded="<?= $section['active'] ? 'true' : 'false' ?>">
                        <i class="bi bi-<?= esc($section['icon']) ?>"></i><span><?= esc($section['label']) ?></span><i class="bi bi-chevron-down chev"></i>
                    </button>
                    <div class="collapse <?= $section['active'] ? 'show' : '' ?>" id="navsec<?= $i ?>">
                        <?php foreach ($section['items'] as $item): ?>
                            <a href="<?= esc($item['href']) ?>" class="nav-sub-link <?= $item['active'] ? 'active' : '' ?>" data-menu-item <?= $item['active'] ? 'aria-current="page"' : '' ?>>
                                <i class="bi bi-<?= esc($item['icon'] ?? 'dot') ?>"></i><span><?= esc($item['label']) ?></span>
                            </a>
                        <?php endforeach ?>
                    </div>
                </div>
                <?php endif ?>
            <?php endforeach ?>
        </nav>
        <div class="sidebar-foot small">DGR Office · <?= esc(date('Y')) ?></div>
    </aside>

    <div class="app-main">
        <header class="app-topbar">
            <button class="btn btn-icon d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Open menu"><i class="bi bi-list"></i></button>
            <form class="topbar-search" action="<?= site_url('search') ?>" method="get" role="search">
                <i class="bi bi-search"></i>
                <input type="search" name="q" class="form-control" placeholder="Search clients, invoices, leads, applicants…" value="<?= esc((string) service('request')->getGet('q')) ?>" aria-label="Global search" data-global-search autocomplete="off">
                <div class="search-suggest dropdown-menu" data-search-suggest></div>
            </form>
            <div class="topbar-actions">
                <?php if ($quick !== []): ?>
                <div class="dropdown">
                    <button class="btn btn-gold btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-plus-lg"></i><span class="d-none d-md-inline ms-1">New</span></button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <?php foreach ($quick as [$label, $url, $icon]): ?>
                            <li><a class="dropdown-item" href="<?= site_url($url) ?>"><i class="bi bi-<?= $icon ?> me-2"></i><?= esc($label) ?></a></li>
                        <?php endforeach ?>
                    </ul>
                </div>
                <?php endif ?>
                <div class="dropdown">
                    <button class="btn btn-icon position-relative" type="button" data-bs-toggle="dropdown" aria-label="Notifications" data-notif-toggle>
                        <i class="bi bi-bell"></i>
                        <?php if ($unread > 0): ?><span class="notif-dot" data-notif-count><?= $unread > 99 ? '99+' : $unread ?></span><?php endif ?>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end notif-menu shadow" data-notif-menu>
                        <div class="px-3 py-2 d-flex justify-content-between align-items-center border-bottom">
                            <strong>Notifications</strong>
                            <a href="<?= site_url('notifications') ?>" class="small">View all</a>
                        </div>
                        <div data-notif-list class="notif-list"><div class="p-3 text-muted small">Loading…</div></div>
                    </div>
                </div>
                <div class="dropdown">
                    <button class="btn btn-profile dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="avatar"><?= esc(mb_strtoupper(mb_substr(current_user_name(), 0, 1))) ?></span>
                        <span class="d-none d-md-inline"><?= esc(current_user_name()) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li><a class="dropdown-item" href="<?= site_url('profile') ?>"><i class="bi bi-person me-2"></i>My profile & sessions</a></li>
                        <li><a class="dropdown-item" href="<?= site_url('profile/password') ?>"><i class="bi bi-key me-2"></i>Change password</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= site_url('logout') ?>"><i class="bi bi-box-arrow-right me-2"></i>Sign out</a></li>
                    </ul>
                </div>
            </div>
        </header>

        <main id="main" class="app-content" tabindex="-1">
            <div class="page-head">
                <div>
                    <?php if ($breadcrumbs !== []): ?>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="<?= site_url('/') ?>">Home</a></li>
                            <?php foreach ($breadcrumbs as $bc): ?>
                                <?php if (isset($bc[1])): ?>
                                    <li class="breadcrumb-item"><a href="<?= esc(site_url($bc[1])) ?>"><?= esc($bc[0]) ?></a></li>
                                <?php else: ?>
                                    <li class="breadcrumb-item active" aria-current="page"><?= esc($bc[0]) ?></li>
                                <?php endif ?>
                            <?php endforeach ?>
                        </ol>
                    </nav>
                    <?php endif ?>
                    <h1 class="page-title"><?= esc($pageTitle) ?></h1>
                </div>
                <div class="page-actions"><?= $this->renderSection('pageActions') ?></div>
            </div>

            <?= view('partials/flash') ?>
            <?= $this->renderSection('content') ?>
        </main>
    </div>
</div>

<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true" aria-labelledby="confirmModalTitle">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title fs-5" id="confirmModalTitle">Please confirm</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body" data-confirm-body>Are you sure?</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-dark" data-confirm-ok>Confirm</button>
            </div>
        </div>
    </div>
</div>
<div class="toast-container position-fixed bottom-0 end-0 p-3" data-toasts></div>

<script src="<?= base_url('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('assets/js/app.js') ?>"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
