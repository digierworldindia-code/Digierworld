<!doctype html>
<html lang="en-IN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Access denied · DGR Office</title>
    <link rel="stylesheet" href="<?= function_exists('base_url') ? base_url('assets/vendor/bootstrap/bootstrap.min.css') : '/assets/vendor/bootstrap/bootstrap.min.css' ?>">
    <link rel="stylesheet" href="<?= function_exists('base_url') ? base_url('assets/css/app.css') : '/assets/css/app.css' ?>">
</head>
<body>
<main class="auth-wrap">
    <div class="auth-card text-center">
        <div class="display-6 fw-bold mb-2">403</div>
        <h1 class="h5">You don't have access to this page</h1>
        <p class="text-muted small mb-4">Your role does not include the permission required for this action or record. If you need access, ask the owner/administrator to review your role in Users &amp; Roles.</p>
        <a href="<?= function_exists('site_url') ? site_url('/') : '/' ?>" class="btn btn-dark">Back to dashboard</a>
    </div>
</main>
</body>
</html>
