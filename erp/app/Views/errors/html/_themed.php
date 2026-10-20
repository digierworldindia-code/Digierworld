<?php
/*
 * Shared, self-contained error page in the DGR Office black theme.
 * Expects $errCode, $errTitle, $errText. Works even when helpers are not
 * loaded (falls back to root-relative asset paths). Never shows exception
 * details to users.
 */
$assetUrl = static function (string $path): string {
    if (function_exists('asset')) {
        return asset($path);
    }

    return (function_exists('base_url') ? base_url('assets/' . $path) : '/assets/' . $path);
};
$home = function_exists('site_url') ? site_url('/') : '/';
?>
<!doctype html>
<html lang="en-IN" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="color-scheme" content="dark">
    <title><?= htmlspecialchars($errTitle, ENT_QUOTES) ?> · DGR Office</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetUrl('vendor/bootstrap/bootstrap.min.css'), ENT_QUOTES) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($assetUrl('css/dgr-theme.css'), ENT_QUOTES) ?>">
</head>
<body>
<main class="auth-wrap">
    <div class="auth-card text-center">
        <div class="display-6 fw-bold mb-2 error-code"><?= htmlspecialchars((string) $errCode, ENT_QUOTES) ?></div>
        <h1 class="h5"><?= htmlspecialchars($errTitle, ENT_QUOTES) ?></h1>
        <p class="text-muted small mb-4"><?= htmlspecialchars($errText, ENT_QUOTES) ?></p>
        <a href="<?= htmlspecialchars($home, ENT_QUOTES) ?>" class="btn btn-primary">Back to dashboard</a>
    </div>
</main>
</body>
</html>
