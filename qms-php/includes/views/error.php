<?php
/**
 * Error page. Variables: $status, $title, $message.
 */
defined('QMS') || exit;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <link rel="stylesheet" href="<?= asset('bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/qms.css') ?>">
</head>
<body class="qms">
<main class="qms-error-page px-3">
    <div class="code"><?= (int) $status ?></div>
    <h1 class="h3"><?= e($title) ?></h1>
    <p class="lead"><?= e($message) ?></p>
    <p class="text-muted small">Reference: <?= e(request_id()) ?></p>
    <a class="btn btn-primary btn-lg" href="<?= url('index.php') ?>">Go to start page</a>
</main>
</body>
</html>
