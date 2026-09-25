<?php
/**
 * Public website layout.
 *
 * @var array  $meta    from App\Libraries\Seo::meta()
 * @var list<array> $schemas  JSON-LD blocks for this page
 * @var string $active  navigation key of the current page
 */
$schemas ??= [];
$active  ??= '';
$ga      = config('Polyfix')->ga4MeasurementId;
$gsc     = config('Polyfix')->gscVerification;
?><!doctype html>
<html lang="en-IN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($meta['title']) ?></title>
    <meta name="description" content="<?= esc($meta['description'], 'attr') ?>">
    <?php if ($meta['keywords'] !== []): ?><meta name="keywords" content="<?= esc(implode(', ', $meta['keywords']), 'attr') ?>"><?php endif ?>
    <meta name="robots" content="<?= esc($meta['robots'], 'attr') ?>">
    <link rel="canonical" href="<?= esc($meta['canonical'], 'attr') ?>">
    <meta name="application-name" content="<?= esc(brand('name'), 'attr') ?>">
    <meta name="theme-color" content="#fbf9f5">
    <meta property="og:type" content="<?= esc($meta['type'], 'attr') ?>">
    <meta property="og:site_name" content="<?= esc(brand('name'), 'attr') ?>">
    <meta property="og:locale" content="<?= esc(brand('locale'), 'attr') ?>">
    <meta property="og:title" content="<?= esc($meta['og_title'], 'attr') ?>">
    <meta property="og:description" content="<?= esc($meta['og_description'], 'attr') ?>">
    <meta property="og:url" content="<?= esc($meta['canonical'], 'attr') ?>">
    <meta property="og:image" content="<?= esc($meta['image'], 'attr') ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="<?= esc($meta['twitter_card'], 'attr') ?>">
    <meta name="twitter:title" content="<?= esc($meta['og_title'], 'attr') ?>">
    <meta name="twitter:description" content="<?= esc($meta['og_description'], 'attr') ?>">
    <meta name="twitter:image" content="<?= esc($meta['image'], 'attr') ?>">
    <?php if ($gsc !== ''): ?><meta name="google-site-verification" content="<?= esc($gsc, 'attr') ?>"><?php endif ?>
    <link rel="icon" href="<?= base_url('images/polyfix-logo.svg') ?>" type="image/svg+xml">
    <link rel="preload" href="<?= base_url('assets/fonts/fraunces-latin.woff2') ?>" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= base_url('assets/fonts/inter-latin.woff2') ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= base_url('assets/css/fonts.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/site.css') ?>">
    <?php foreach ($schemas as $schema): ?>
    <?= \App\Libraries\Seo::jsonLd($schema) ?>
    <?php endforeach ?>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<?= view('partials/site_header', ['active' => $active]) ?>
<main id="main">
    <?= view('partials/flash') ?>
    <?= $this->renderSection('content') ?>
</main>
<?= view('partials/site_footer') ?>
<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>" defer></script>
<?php if ($ga !== ''): ?>
<script src="https://www.googletagmanager.com/gtag/js?id=<?= esc($ga, 'url') ?>" async></script>
<script src="<?= base_url('assets/js/analytics.js') ?>" data-ga="<?= esc($ga, 'attr') ?>" defer></script>
<?php endif ?>
</body>
</html>
