<?php
/**
 * @var string $slug
 * @var array<string, mixed> $def
 * @var array<string, mixed>|null $row
 * @var array<string, array<string, string>> $options
 * @var array<string, string> $errors
 */
$isNew = $row === null;
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="qms-page-head">
    <div><h1><?= $isNew ? 'New ' . esc($def['singular']) : esc(ucfirst($def['singular'])) . ' ' . esc((string) $row[$def['code']]) ?></h1>
        <p class="qms-sub"><?= esc($def['help']) ?></p></div>
    <a class="btn btn-outline-secondary" href="<?= site_url('masters/' . $slug) ?>"><?= qms_icon('bi-arrow-left') ?> Back</a>
</div>
<div class="card col-xl-8"><div class="card-body">
<form method="post" action="<?= site_url('masters/' . $slug . ($isNew ? '' : '/' . $row['id'])) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3">
    <?php foreach ($def['fields'] as $name => $field):
        $type     = $field['type'] ?? 'text';
        $value    = old($name) ?? ($row[$name] ?? ($type === 'bool' ? '1' : ''));
        $required = str_contains($field['rules'], 'required');
        $locked   = ! $isNew && $name === $def['code'];
        $id       = 'f-' . $name; ?>
        <div class="<?= $type === 'textarea' ? 'col-12' : 'col-md-6' ?>">
        <?php if ($type === 'bool'): ?>
            <div class="form-check form-switch mt-4">
                <input class="form-check-input" type="checkbox" role="switch" id="<?= $id ?>" name="<?= $name ?>" value="1"<?= (string) $value === '1' ? ' checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="<?= $id ?>"><?= esc($field['label']) ?></label>
            </div>
        <?php else: ?>
            <label class="form-label<?= $required ? ' required' : '' ?>" for="<?= $id ?>"><?= esc($field['label']) ?></label>
            <?php if ($type === 'select'): ?>
                <select class="form-select form-select-lg<?= field_invalid($errors, $name) ?>" id="<?= $id ?>" name="<?= $name ?>"<?= $required ? ' required' : '' ?>>
                    <option value=""><?= $required ? 'Choose…' : '—' ?></option>
                    <?php foreach ($options[$name] ?? [] as $optValue => $optLabel): ?>
                        <option value="<?= esc((string) $optValue, 'attr') ?>"<?= (string) $value === (string) $optValue ? ' selected' : '' ?>><?= esc($optLabel) ?></option>
                    <?php endforeach ?>
                </select>
            <?php elseif ($type === 'textarea'): ?>
                <textarea class="form-control<?= field_invalid($errors, $name) ?>" id="<?= $id ?>" name="<?= $name ?>" rows="3"><?= esc((string) $value) ?></textarea>
            <?php else: ?>
                <input class="form-control form-control-lg<?= field_invalid($errors, $name) ?>" id="<?= $id ?>" name="<?= $name ?>"
                       type="<?= in_array($type, ['date', 'time', 'number'], true) ? $type : 'text' ?>"
                       value="<?= esc($type === 'time' ? substr((string) $value, 0, 5) : (string) $value, 'attr') ?>"
                       <?= $required ? 'required' : '' ?> <?= $locked ? 'readonly' : '' ?>>
            <?php endif ?>
            <?php if ($locked): ?><div class="form-text">Codes cannot be changed.</div><?php endif ?>
        <?php endif ?>
        <?= field_error($errors, $name) ?>
        </div>
    <?php endforeach ?>
    </div>
    <button class="btn btn-primary btn-lg mt-4" type="submit" data-once><?= qms_icon('bi-check2') ?> <?= $isNew ? 'Create' : 'Save' ?></button>
</form>
</div></div>
<?= $this->endSection() ?>
