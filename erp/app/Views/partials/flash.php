<?php
$messages = [
    'success' => ['success', 'check-circle'],
    'message' => ['success', 'check-circle'],
    'warning' => ['warning', 'exclamation-triangle'],
    'error'   => ['danger', 'x-octagon'],
    'info'    => ['info', 'info-circle'],
];
foreach ($messages as $key => [$cls, $icon]):
    $msg = session()->getFlashdata($key);
    if ($msg === null || $msg === '') {
        continue;
    }
?>
<div class="alert alert-<?= $cls ?> alert-dismissible fade show d-flex align-items-start gap-2" role="alert">
    <i class="bi bi-<?= $icon ?> mt-1"></i>
    <div><?= esc(is_array($msg) ? implode(' ', $msg) : $msg) ?></div>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endforeach ?>
<?php $errs = flash_errors(); if ($errs !== [] && count($errs) > 0): ?>
<div class="alert alert-danger py-2 small" role="alert">
    <ul class="mb-0 ps-3">
        <?php foreach ($errs as $field => $e): ?><li><?= esc(is_array($e) ? implode(', ', $e) : $e) ?></li><?php endforeach ?>
    </ul>
</div>
<?php endif ?>
