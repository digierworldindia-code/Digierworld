<?php

declare(strict_types=1);

/*
 * Copies the compiled front-end libraries from vendor/ into public/assets/vendor/
 * so the browser loads them from the QMS server itself (no CDN, works on an
 * isolated plant network, compatible with the strict Content-Security-Policy).
 * Runs automatically after `composer install` / `composer update`.
 */

$root = dirname(__DIR__);
$copy = [
    'vendor/twbs/bootstrap/dist/css/bootstrap.min.css'          => 'public/assets/vendor/bootstrap/css/bootstrap.min.css',
    'vendor/twbs/bootstrap/dist/js/bootstrap.bundle.min.js'     => 'public/assets/vendor/bootstrap/js/bootstrap.bundle.min.js',
    'vendor/twbs/bootstrap-icons/font/bootstrap-icons.min.css'  => 'public/assets/vendor/bootstrap-icons/bootstrap-icons.min.css',
    'vendor/twbs/bootstrap-icons/font/fonts/bootstrap-icons.woff2' => 'public/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2',
    'vendor/twbs/bootstrap-icons/font/fonts/bootstrap-icons.woff'  => 'public/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff',
];

foreach ($copy as $from => $to) {
    $src = $root . '/' . $from;
    $dst = $root . '/' . $to;
    if (! is_file($src)) {
        fwrite(STDERR, "publish-assets: missing {$from}\n");
        exit(1);
    }
    if (! is_dir(dirname($dst)) && ! mkdir(dirname($dst), 0755, true) && ! is_dir(dirname($dst))) {
        fwrite(STDERR, "publish-assets: cannot create " . dirname($dst) . "\n");
        exit(1);
    }
    copy($src, $dst);
}

echo "publish-assets: " . count($copy) . " files copied to public/assets/vendor\n";
