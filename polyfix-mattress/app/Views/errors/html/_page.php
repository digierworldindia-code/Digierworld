<?php
/**
 * Stand-alone error page. Deliberately touches nothing but static assets:
 * it must still render when the database is the thing that failed.
 *
 * @var int    $code
 * @var string $title
 * @var string $body
 */
$home = function_exists('site_url') ? site_url('/') : '/';
$css  = static fn (string $p): string => function_exists('base_url') ? base_url($p) : '/' . $p;
?><!doctype html>
<html lang="en-IN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= esc($title) ?> | <?= esc(brand('name')) ?></title>
    <link rel="stylesheet" href="<?= $css('assets/css/fonts.css') ?>">
    <link rel="stylesheet" href="<?= $css('assets/vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= $css('assets/css/site.css') ?>">
</head>
<body>
<header class="site-header"><div class="container py-3"><a class="wordmark text-decoration-none" href="<?= esc($home, 'attr') ?>"><?= brand_wordmark() ?></a></div></header>
<main class="section">
    <div class="container container-narrow">
        <p class="eyebrow">Error <?= (int) $code ?></p>
        <h1><?= esc($title) ?></h1>
        <p class="lede mt-3"><?= esc($body) ?></p>
        <p class="mt-4 d-flex flex-wrap gap-3">
            <a class="btn btn-primary" href="<?= esc($home, 'attr') ?>">Go to the home page</a>
            <a class="btn btn-outline-dark" href="<?= esc(function_exists('site_url') ? site_url('warranty') : '/warranty', 'attr') ?>">Verify a mattress</a>
        </p>
    </div>
</main>
</body>
</html>
