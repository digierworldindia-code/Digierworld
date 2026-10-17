<?php
/**
 * Shop-floor layout — the warehouse panel and the field-agent panel.
 *
 * Deliberately not the console: these are two phone screens held in one hand
 * beside a stack of mattresses, not a desk tool. There is no sidebar and no
 * search; there is a back arrow, a title, and whatever the screen is for.
 *
 * @var string      $title
 * @var string|null $back     URL of the screen one level up, if any
 * @var string|null $home     where the brand mark goes (defaults by role)
 * @var bool|null   $scanner  load the camera scanner on this page
 */
$ctx  = service('requestContext');
$user = $ctx->user();
$home ??= $ctx->can('warehouse:scan') && ! $ctx->can('survey:conduct') ? 'warehouse' : 'agent';
?><!doctype html>
<html lang="en-IN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#121417">
    <title><?= esc($title ?? 'POLYFIZZ') ?> · <?= esc(brand('shortName')) ?></title>
    <link rel="icon" href="<?= base_url('images/polyfizz-logo.svg') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= base_url('assets/css/fonts.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/console.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/floor.css') ?>">
</head>
<body>
<a class="skip-link" href="#content">Skip to content</a>
<div class="floor">
    <header class="floor-top">
        <?php if (! empty($back)): ?>
            <a class="back" href="<?= esc($back, 'attr') ?>" aria-label="Back"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
        <?php endif ?>
        <a class="brand" href="<?= site_url($home) ?>"><?= brand_wordmark() ?></a>
        <div class="dropdown ms-auto">
            <button class="btn btn-sm btn-dark" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person-circle" aria-hidden="true"></i>
                <span class="visually-hidden">Account</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><h6 class="dropdown-header"><?= esc($user['full_name'] ?? '') ?><br>
                    <span class="fw-normal"><?= esc(implode(', ', array_map([\App\Libraries\Rbac::class, 'roleName'], $user['roles'] ?? []))) ?></span></h6></li>
                <li><a class="dropdown-item" href="<?= site_url('account/security') ?>"><i class="bi bi-shield-lock me-2"></i>Password &amp; security</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><form method="post" action="<?= site_url('logout') ?>"><?= csrf_field() ?><button class="dropdown-item" type="submit"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button></form></li>
            </ul>
        </div>
    </header>

    <main class="floor-main" id="content">
        <?= view('partials/console_flash') ?>
        <?= $this->renderSection('content') ?>
    </main>
</div>
<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>" defer></script>
<script src="<?= base_url('assets/js/console.js') ?>" defer></script>
<?php if (! empty($scanner)): ?>
<script src="<?= base_url('assets/js/qr-scanner.js') ?>" defer></script>
<?php endif ?>
</body>
</html>
