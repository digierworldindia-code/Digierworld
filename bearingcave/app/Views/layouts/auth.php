<!doctype html>
<html lang="en">
<head>
<?php $robots = 'noindex,nofollow'; ?>
<?= view('components/head', get_defined_vars()) ?>
</head>
<body class="bg-soft">
<main id="main" class="min-vh-100 d-flex flex-column">
    <div class="container py-4">
        <a class="d-inline-flex align-items-center gap-2 fw-bold fs-5 text-navy text-decoration-none" href="<?= site_url('/') ?>"><span class="brand-mark" aria-hidden="true"><i class="bi bi-gear-wide-connected"></i></span>BearingCave</a>
    </div>
    <div class="container flex-grow-1 d-flex align-items-start justify-content-center pb-5">
        <div class="w-100" style="max-width: <?= esc($authWidth ?? '440px', 'attr') ?>">
            <?= view('components/flash') ?>
            <?= $this->renderSection('content') ?>
        </div>
    </div>
</main>
<script src="<?= base_url('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('assets/js/app.js') ?>?v=1"></script>
</body>
</html>
