<?php
/**
 * Mithaas — document head, navigation, mobile drawer and the sticky action bar.
 * Included by every page. Set these before requiring it:
 *
 *   $pageTitle        required  <title>, og:title, twitter:title
 *   $metaDescription  required  meta description, og / twitter descriptions
 *   $canonicalUrl     optional  defaults to this page's URL under SITE_URL
 *   $breadcrumb       optional  ['Home' => 'index.php', 'Menu' => 'menu.php']
 *   $robots           optional  defaults to index, follow, max-image-preview:large
 *   $ogType           optional  defaults to website
 *   $ogTitle          optional  defaults to $pageTitle
 *   $ogDescription    optional  defaults to $metaDescription
 *   $ogImage          optional  defaults to the brand share card
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$currentPage     = current_page();
$pageTitle       = $pageTitle       ?? SITE_NAME;
$metaDescription = $metaDescription ?? '';
$canonicalUrl    = $canonicalUrl    ?? site_url($currentPage);
$breadcrumb      = $breadcrumb      ?? null;
$robots          = $robots          ?? 'index, follow, max-image-preview:large';
$ogType          = $ogType          ?? 'website';
$ogTitle         = $ogTitle         ?? $pageTitle;
$ogDescription   = $ogDescription   ?? $metaDescription;
$ogImage         = $ogImage         ?? site_url(OG_IMAGE);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e($metaDescription) ?>">
<link rel="canonical" href="<?= e($canonicalUrl) ?>">
<meta name="theme-color" content="#FBF7EF">
<meta name="robots" content="<?= e($robots) ?>">

<meta property="og:site_name" content="Mithaas">
<meta property="og:type" content="<?= e($ogType) ?>">
<meta property="og:title" content="<?= e($ogTitle) ?>">
<meta property="og:description" content="<?= e($ogDescription) ?>">
<meta property="og:url" content="<?= e($canonicalUrl) ?>">
<meta property="og:image" content="<?= e($ogImage) ?>">
<meta property="og:image:alt" content="Mithaas — Sweets, Bakery, Restaurant">
<meta property="og:locale" content="en_IN">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($ogTitle) ?>">
<meta name="twitter:description" content="<?= e($ogDescription) ?>">
<meta name="twitter:image" content="<?= e($ogImage) ?>">

<link rel="icon" href="assets/img/brand/favicon.png" sizes="any">
<link rel="apple-touch-icon" href="assets/img/brand/apple-touch-icon.png">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style"
  href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=Jost:wght@300;400;500&display=swap">
<link rel="stylesheet"
  href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=Jost:wght@300;400;500&display=swap">

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
  integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/css/mithaas.css">
<?php if ($breadcrumb): ?>
<script type="application/ld+json"><?= breadcrumb_jsonld($breadcrumb) ?></script>
<?php endif; ?>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<header class="nav-mithaas" data-solid="false">
  <div class="container">
    <a class="brand" href="index.php" aria-label="Mithaas — home">
      <img class="brand__logo" data-logo="nav" src="assets/img/brand/mithaas-logo.png"
           alt="Mithaas — Sweets, Bakery, Restaurant" width="240" height="150">
    </a>

    <nav class="nav-links" aria-label="Primary"><?php foreach (NAV as $file => $label): ?><a class="nav-link-m" href="<?= $file ?>" data-navlink="<?= $file ?>"<?= $file === $currentPage ? ' aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach; ?></nav>

    <a class="btn btn-sm-brand nav-cta" href="contact.php">Visit Us</a>

    <button class="navbar-toggler-m" type="button" data-bs-toggle="offcanvas"
            data-bs-target="#menuDrawer" aria-controls="menuDrawer" aria-label="Open menu">
      <span></span><span></span><span></span>
    </button>
  </div>
</header>

<div class="offcanvas offcanvas-end offcanvas-mithaas" tabindex="-1" id="menuDrawer"
     aria-labelledby="menuDrawerLabel">
  <div class="offcanvas-header">
    <h2 class="offcanvas-title visually-hidden" id="menuDrawerLabel">Menu</h2>
    <a class="brand" href="index.php" aria-label="Mithaas — home">
      <img class="brand__logo" data-logo="nav" src="assets/img/brand/mithaas-logo.png"
           alt="Mithaas — Sweets, Bakery, Restaurant" width="200" height="125">
    </a>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close menu"></button>
  </div>
  <div class="offcanvas-body">
    <nav class="drawer-links" aria-label="Mobile"><?php $i = 0; foreach (NAV as $file => $label): ?><a class="drawer-link" href="<?= $file ?>" data-navlink="<?= $file ?>" style="--i:<?= $i ?>"<?= $file === $currentPage ? ' aria-current="page"' : '' ?>><span class="idx"><?= sprintf('%02d', ++$i) ?></span><span><?= e($label) ?></span></a><?php endforeach; ?></nav>
    <div class="drawer-foot">
      <a class="btn" href="menu.php">Explore Our Menu</a>
      <p class="form-note mt-3 mb-0">
        <span data-field="addressInline" data-pending="Address to be confirmed"></span>
      </p>
    </div>
  </div>
</div>

<nav class="actionbar" data-show="false" aria-label="Quick actions">
  <a data-action="call" href="#"><i class="bi bi-telephone" aria-hidden="true"></i>Call</a>
  <a data-action="whatsapp" data-message="Hello Mithaas, I'd like to place an order." href="#"><i class="bi bi-whatsapp" aria-hidden="true"></i>WhatsApp</a>
  <a href="menu.php"><i class="bi bi-journal-text" aria-hidden="true"></i>Menu</a>
</nav>