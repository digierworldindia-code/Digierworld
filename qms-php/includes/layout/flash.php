<?php
/**
 * Messages from the previous action (success, info, warning, error + field errors).
 */
defined('QMS') || exit;

$qmsMessages = [
    'success' => ['alert-success', 'bi-check-circle-fill'],
    'info'    => ['alert-primary', 'bi-info-circle-fill'],
    'warning' => ['alert-warning', 'bi-exclamation-triangle-fill'],
    'error'   => ['alert-danger', 'bi-x-octagon-fill'],
];
$qmsErrors = array_values(array_unique(array_filter(form_errors(), 'is_string')));

foreach ($qmsMessages as $qmsKey => [$qmsClass, $qmsIcon]):
    $qmsText = flash_get($qmsKey);
    if (is_string($qmsText) && $qmsText !== ''):
        $qmsDetails = $qmsKey === 'error' ? array_values(array_filter($qmsErrors, static fn (string $m): bool => ! str_contains($qmsText, $m))) : []; ?>
        <div class="alert <?= $qmsClass ?> d-flex align-items-start gap-2" role="<?= $qmsKey === 'error' ? 'alert' : 'status' ?>">
            <?= qms_icon($qmsIcon, 'mt-1') ?>
            <div>
                <?= e($qmsText) ?>
                <?php if ($qmsDetails !== []): ?><ul class="mb-0 mt-1"><?php foreach ($qmsDetails as $qmsLine): ?><li><?= e($qmsLine) ?></li><?php endforeach ?></ul><?php endif ?>
            </div>
        </div>
    <?php endif;
endforeach;

if ($qmsErrors !== [] && ! is_string(flash_get('error'))): ?>
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0"><?php foreach ($qmsErrors as $qmsLine): ?><li><?= e($qmsLine) ?></li><?php endforeach ?></ul>
    </div>
<?php endif;
