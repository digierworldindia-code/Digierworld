<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Please wait</title><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap/bootstrap.min.css') ?>"></head>
<body class="d-flex align-items-center justify-content-center min-vh-100 bg-light"><div class="text-center p-4" style="max-width:480px">
<h1 class="h4">Too many attempts</h1><p class="text-muted">For your security, please wait about <?= max(1, (int) $seconds) ?> second(s) and try again. Your account has not been locked.</p>
<a class="btn btn-primary" href="javascript:history.back()">Go back</a></div></body></html>
