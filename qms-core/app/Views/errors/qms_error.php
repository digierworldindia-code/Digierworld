<?php
/**
 * @var int    $status
 * @var string $message
 * @var string $requestId
 */
$titles = [401 => 'Please log in', 403 => 'Not allowed', 404 => 'Not found', 409 => 'Changed by someone else',
    419 => 'Form expired', 423 => 'Record locked', 429 => 'Too many attempts', 500 => 'Something went wrong'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($titles[$status] ?? 'Error') ?></title>
    <link rel="stylesheet" href="<?= qms_asset('assets/vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= qms_asset('assets/css/qms.css') ?>">
</head>
<body class="qms">
<main class="qms-error-page px-3">
    <div class="code"><?= (int) $status ?></div>
    <h1 class="h3"><?= esc($titles[$status] ?? 'Error') ?></h1>
    <p class="lead"><?= esc($message) ?></p>
    <p class="text-muted small">Reference: <?= esc($requestId) ?></p>
    <a class="btn btn-primary btn-lg" href="<?= site_url('/') ?>">Go to start page</a>
</main>
</body>
</html>
