<?php
/*
 * The one place pages load styles: official Bootstrap 5.3 and Bootstrap
 * Icons (self-hosted, unmodified, verified — see public/assets/vendor/MANIFEST.json),
 * then the DGR design system. No CDN, no external font, no inline styles.
 * theme-init.js runs right after them (blocking, tiny) so the Light / Dark /
 * System choice is applied before the first paint.
 */
?>
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/dgr-theme.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/custom.css') ?>">
    <script src="<?= asset('js/theme-init.js') ?>"></script>
