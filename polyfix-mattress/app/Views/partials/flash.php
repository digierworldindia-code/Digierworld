<?php
/**
 * One-time messages set by the previous request. Always escaped: a message can
 * echo a value the visitor typed.
 */
$flash = [
    'success' => ['alert-success', 'bi-check-circle'],
    'error'   => ['alert-danger', 'bi-exclamation-triangle'],
    'notice'  => ['alert-secondary', 'bi-info-circle'],
];
$any = false;
foreach ($flash as $key => $_) {
    $any = $any || session()->getFlashdata($key) !== null;
}
if (! $any) {
    return;
}
?>
<div class="container pt-4" role="status" aria-live="polite">
    <?php foreach ($flash as $key => [$class, $icon]): ?>
        <?php if (($message = session()->getFlashdata($key)) !== null): ?>
        <div class="alert <?= $class ?> d-flex gap-2 align-items-start" role="alert">
            <i class="bi <?= $icon ?>" aria-hidden="true"></i><div><?= esc($message) ?></div>
        </div>
        <?php endif ?>
    <?php endforeach ?>
</div>
