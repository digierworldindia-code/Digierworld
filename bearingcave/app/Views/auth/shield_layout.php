<!doctype html>
<html lang="en">
<head>
<?php $robots = 'noindex,nofollow'; $title = trim(strip_tags($this->renderSection('title'))); ?>
<?= view('components/head', get_defined_vars()) ?>
</head>
<body class="bg-soft">
<div class="container py-4"><a class="d-inline-flex align-items-center gap-2 fw-bold fs-5 text-navy text-decoration-none" href="<?= site_url('/') ?>"><span class="brand-mark" aria-hidden="true"><i class="bi bi-gear-wide-connected"></i></span>BearingCave</a></div>
<main id="main" role="main" class="container"><?= $this->renderSection('main') ?></main>
<script src="<?= base_url('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>
