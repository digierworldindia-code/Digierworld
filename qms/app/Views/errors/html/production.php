<?php
// Shown for unexpected errors when CI_ENVIRONMENT = production. Never shows details.
$reference = function_exists('service') ? service('requestContext')->requestId() : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Something went wrong</title>
    <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/qms.css">
</head>
<body class="qms">
<main class="qms-error-page px-3">
    <div class="code">500</div>
    <h1 class="h3">Something went wrong</h1>
    <p class="lead">The error has been logged. Your saved data is safe. Please try again; if it keeps happening, give this reference to the administrator.</p>
    <p class="text-muted small">Reference: <?= htmlspecialchars((string) $reference, ENT_QUOTES, 'UTF-8') ?></p>
    <a class="btn btn-primary btn-lg" href="/">Go to start page</a>
</main>
</body>
</html>
