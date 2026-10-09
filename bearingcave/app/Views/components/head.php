<?php
/**
 * Shared <head> content. Expects optional: $title, $metaDescription, $canonical, $robots, $jsonLd (array)
 */
$siteName   = policy('platform.name', 'BearingCave');
$pageTitle  = isset($title) && $title !== '' ? $title . ' | ' . $siteName : policy('seo.default_title', $siteName);
$desc       = $metaDescription ?? policy('seo.default_description', '');
$indexing   = (bool) policy('seo.indexing_enabled', false);
$robotsMeta = $indexing ? ($robots ?? 'index,follow') : 'noindex,nofollow';
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($pageTitle) ?></title>
<meta name="description" content="<?= esc($desc, 'attr') ?>">
<meta name="robots" content="<?= esc($robotsMeta, 'attr') ?>">
<?php if (! empty($canonical)): ?><link rel="canonical" href="<?= esc($canonical, 'attr') ?>"><?php endif ?>
<?php if ($v = policy('seo.google_site_verification')): ?><meta name="google-site-verification" content="<?= esc($v, 'attr') ?>"><?php endif ?>
<meta property="og:site_name" content="<?= esc($siteName, 'attr') ?>">
<meta property="og:title" content="<?= esc($pageTitle, 'attr') ?>">
<meta property="og:description" content="<?= esc($desc, 'attr') ?>">
<meta property="og:type" content="website">
<?php if (! empty($canonical)): ?><meta property="og:url" content="<?= esc($canonical, 'attr') ?>"><?php endif ?>
<meta name="theme-color" content="#0b1f3a">
<link rel="icon" href="<?= base_url('assets/images/favicon.svg') ?>" type="image/svg+xml">
<link rel="preload" href="<?= base_url('assets/vendor/inter/files/inter-latin-400-normal.woff2') ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('assets/css/bearingcave.css') ?>?v=1">
<?php if (! empty($jsonLd)): ?>
<script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif ?>
