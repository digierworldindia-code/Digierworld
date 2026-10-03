<?php
$messages = [
    'success' => ['alert-success', 'bi-check-circle-fill'],
    'info'    => ['alert-primary', 'bi-info-circle-fill'],
    'warning' => ['alert-warning', 'bi-exclamation-triangle-fill'],
    'error'   => ['alert-danger', 'bi-x-octagon-fill'],
];
$errors = session()->getFlashdata('errors');
$errors = is_array($errors) ? array_values(array_unique(array_filter($errors, 'is_string'))) : [];

foreach ($messages as $key => [$class, $icon]):
    $text = session()->getFlashdata($key);
    if (is_string($text) && $text !== ''):
        $details = $key === 'error' ? array_values(array_filter($errors, static fn (string $e): bool => $e !== $text)) : []; ?>
        <div class="alert <?= $class ?> d-flex align-items-start gap-2" role="<?= $key === 'error' ? 'alert' : 'status' ?>">
            <?= qms_icon($icon, 'mt-1') ?>
            <div>
                <?= esc($text) ?>
                <?php if ($details !== []): ?><ul class="mb-0 mt-1"><?php foreach ($details as $message): ?><li><?= esc($message) ?></li><?php endforeach ?></ul><?php endif ?>
            </div>
        </div>
    <?php endif;
endforeach;

if ($errors !== [] && ! is_string(session()->getFlashdata('error'))): ?>
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0"><?php foreach ($errors as $message): ?><li><?= esc($message) ?></li><?php endforeach ?></ul>
    </div>
<?php endif ?>
