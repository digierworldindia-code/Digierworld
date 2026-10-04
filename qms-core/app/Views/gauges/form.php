<?php
$isNew = $gauge === null;
$val   = static fn (string $k) => old($k) ?? ($gauge[$k] ?? '');
$input = static function (string $name, string $label, array $errors, string $value, string $type = 'text', bool $required = false, string $extra = ''): string {
    return '<label class="form-label' . ($required ? ' required' : '') . '" for="f-' . $name . '">' . esc($label) . '</label>'
        . '<input class="form-control' . field_invalid($errors, $name) . '" id="f-' . $name . '" name="' . $name . '" type="' . $type . '" value="' . esc($value, 'attr') . '"' . ($required ? ' required' : '') . ' ' . $extra . '>'
        . field_error($errors, $name);
};
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="qms-page-head">
    <div><h1><?= $isNew ? 'Register gauge' : 'Edit gauge ' . esc($gauge['gauge_code']) ?></h1>
        <p class="qms-sub">Calibration dates change only by recording a calibration on the gauge page.</p></div>
    <a class="btn btn-outline-secondary" href="<?= site_url($isNew ? 'gauges' : 'gauges/' . $gauge['id']) ?>"><?= qms_icon('bi-arrow-left') ?> Back</a>
</div>
<form method="post" action="<?= site_url($isNew ? 'gauges' : 'gauges/' . $gauge['id']) ?>" novalidate>
<?= csrf_field() ?>
<div class="card mb-3"><div class="card-body row g-3">
    <div class="col-md-4"><?= $input('gauge_code', 'Gauge ID', $errors, (string) $val('gauge_code'), 'text', $isNew, $isNew ? 'maxlength="40"' : 'readonly') ?></div>
    <div class="col-md-8"><?= $input('gauge_name', 'Name', $errors, (string) $val('gauge_name'), 'text', true, 'maxlength="120"') ?></div>
    <div class="col-md-4">
        <label class="form-label required" for="f-gauge_type_id">Gauge type</label>
        <select class="form-select<?= field_invalid($errors, 'gauge_type_id') ?>" id="f-gauge_type_id" name="gauge_type_id" required>
            <option value="">Choose…</option>
            <?php foreach ($types as $id => $label): ?><option value="<?= $id ?>"<?= (string) $val('gauge_type_id') === (string) $id ? ' selected' : '' ?>><?= esc($label) ?></option><?php endforeach ?>
        </select><?= field_error($errors, 'gauge_type_id') ?>
    </div>
    <div class="col-md-4"><?= $input('make', 'Make', $errors, (string) $val('make')) ?></div>
    <div class="col-md-4"><?= $input('serial_no', 'Serial no.', $errors, (string) $val('serial_no')) ?></div>
    <div class="col-md-4"><?= $input('measuring_range', 'Measuring range', $errors, (string) $val('measuring_range'), 'text', false, 'placeholder="0-150 mm"') ?></div>
    <div class="col-md-4"><?= $input('least_count', 'Least count', $errors, (string) $val('least_count'), 'text', false, 'inputmode="decimal"') ?></div>
    <div class="col-md-4">
        <label class="form-label" for="f-unit_id">Unit</label>
        <select class="form-select" id="f-unit_id" name="unit_id"><option value="">—</option>
            <?php foreach ($units as $id => $label): ?><option value="<?= $id ?>"<?= (string) $val('unit_id') === (string) $id ? ' selected' : '' ?>><?= esc($label) ?></option><?php endforeach ?>
        </select>
    </div>
    <div class="col-md-4"><?= $input('location', 'Location', $errors, (string) $val('location')) ?></div>
    <div class="col-md-4"><?= $input('calibration_frequency_days', 'Calibration frequency (days)', $errors, (string) $val('calibration_frequency_days'), 'number') ?></div>
    <?php if (! $isNew): ?>
    <div class="col-md-4">
        <label class="form-label required" for="f-status">Status</label>
        <select class="form-select" id="f-status" name="status">
            <?php foreach ($statuses as $k => $v): ?><option value="<?= $k ?>"<?= (string) $val('status') === $k ? ' selected' : '' ?>><?= esc($v) ?></option><?php endforeach ?>
        </select>
    </div>
    <?php endif ?>
</div></div>
<?php if ($isNew): ?>
<div class="card mb-3"><div class="card-header">Current calibration (optional, for gauges already calibrated)</div><div class="card-body row g-3">
    <div class="col-md-3"><?= $input('calibration_date', 'Last calibration date', $errors, (string) (old('calibration_date') ?? ''), 'date') ?></div>
    <div class="col-md-3"><?= $input('due_date', 'Calibration due date', $errors, (string) (old('due_date') ?? ''), 'date') ?></div>
    <div class="col-md-3"><?= $input('certificate_no', 'Certificate no.', $errors, (string) (old('certificate_no') ?? '')) ?></div>
    <div class="col-md-3"><?= $input('agency', 'Agency', $errors, (string) (old('agency') ?? '')) ?></div>
</div></div>
<?php endif ?>
<button class="btn btn-primary btn-lg" type="submit" data-once><?= qms_icon('bi-check2') ?> <?= $isNew ? 'Register gauge' : 'Save' ?></button>
</form>
<?= $this->endSection() ?>
