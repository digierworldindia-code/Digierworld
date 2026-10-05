<?php
/**
 * Register a gauge (gauge_edit.php) or edit one (gauge_edit.php?id=5).
 */
require __DIR__ . '/includes/init.php';
$user = require_permission('gauge.manage');
require_once QMS_ROOT . '/includes/gauges.php';
require_once QMS_ROOT . '/includes/masters.php';

$id     = get_int('id');
$fields = ['gauge_code', 'gauge_name', 'gauge_type_id', 'make', 'serial_no', 'measuring_range', 'least_count', 'unit_id', 'location',
    'calibration_frequency_days', 'status', 'calibration_date', 'due_date', 'certificate_no', 'agency'];

if (is_post()) {
    $input = post_fields($fields);
    try {
        if ($id > 0) {
            gauge_update($id, $input, $user);
        } else {
            $id = gauge_create($input, $user);
        }
    } catch (Throwable $e) {
        back_with_error($e, 'gauge_edit.php' . ($id > 0 ? '?id=' . $id : ''));
    }
    done(get_int('id') > 0 ? 'Gauge saved.' : 'Gauge registered.', 'gauge.php?id=' . $id);
}

$gauge  = $id > 0 ? gauge_find($id) : null;
$isNew  = $gauge === null;
$types  = master_lookup('gauge-types');
$units  = master_lookup('units');
$errors = form_errors();
$val    = static fn (string $k): string => (string) (old($k) ?? ($gauge[$k] ?? ''));
$input  = static function (string $name, string $label, string $value, string $type = 'text', bool $required = false, string $extra = '') use ($errors): string {
    return '<label class="form-label' . ($required ? ' required' : '') . '" for="f-' . $name . '">' . e($label) . '</label>'
        . '<input class="form-control' . field_invalid($errors, $name) . '" id="f-' . $name . '" name="' . $name . '" type="' . $type . '" value="' . e($value) . '"' . ($required ? ' required' : '') . ' ' . $extra . '>'
        . field_error($errors, $name);
};
// Keep a retired type / unit of this gauge selectable.
if ($gauge !== null && ! isset($types[(string) $gauge['gauge_type_id']])) {
    $types[(string) $gauge['gauge_type_id']] = $gauge['type_name'] . ' (retired)';
}
if ($gauge !== null && $gauge['unit_id'] !== null && ! isset($units[(string) $gauge['unit_id']])) {
    $units[(string) $gauge['unit_id']] = ($gauge['unit_symbol'] ?? '') . ' (retired)';
}

$page_title = $isNew ? 'Register gauge' : 'Edit gauge ' . $gauge['gauge_code'];
require QMS_ROOT . '/includes/layout/header.php';
?>
<div class="qms-page-head">
    <div><h1><?= $isNew ? 'Register gauge' : 'Edit gauge ' . e($gauge['gauge_code']) ?></h1>
        <p class="qms-sub">Calibration dates change only by recording a calibration on the gauge page.</p></div>
    <a class="btn btn-outline-secondary" href="<?= url($isNew ? 'gauges.php' : 'gauge.php?id=' . (int) $gauge['id']) ?>"><?= qms_icon('bi-arrow-left') ?> Back</a>
</div>
<form method="post" action="<?= url('gauge_edit.php' . ($isNew ? '' : '?id=' . (int) $gauge['id'])) ?>" novalidate>
<?= csrf_field() ?>
<div class="card mb-3"><div class="card-body row g-3">
    <div class="col-md-4"><?= $input('gauge_code', 'Gauge ID', $val('gauge_code'), 'text', $isNew, $isNew ? 'maxlength="40"' : 'readonly') ?></div>
    <div class="col-md-8"><?= $input('gauge_name', 'Name', $val('gauge_name'), 'text', true, 'maxlength="120"') ?></div>
    <div class="col-md-4">
        <label class="form-label required" for="f-gauge_type_id">Gauge type</label>
        <select class="form-select<?= field_invalid($errors, 'gauge_type_id') ?>" id="f-gauge_type_id" name="gauge_type_id" required>
            <option value="">Choose…</option>
            <?php foreach ($types as $tid => $label): ?><option value="<?= (int) $tid ?>"<?= $val('gauge_type_id') === (string) $tid ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?>
        </select><?= field_error($errors, 'gauge_type_id') ?>
    </div>
    <div class="col-md-4"><?= $input('make', 'Make', $val('make'), 'text', false, 'maxlength="80"') ?></div>
    <div class="col-md-4"><?= $input('serial_no', 'Serial no.', $val('serial_no'), 'text', false, 'maxlength="60"') ?></div>
    <div class="col-md-4"><?= $input('measuring_range', 'Measuring range', $val('measuring_range'), 'text', false, 'placeholder="0-150 mm" maxlength="60"') ?></div>
    <div class="col-md-4"><?= $input('least_count', 'Least count', $val('least_count') !== '' && str_contains($val('least_count'), '.') ? rtrim(rtrim($val('least_count'), '0'), '.') : $val('least_count'), 'text', false, 'inputmode="decimal"') ?></div>
    <div class="col-md-4">
        <label class="form-label" for="f-unit_id">Unit</label>
        <select class="form-select<?= field_invalid($errors, 'unit_id') ?>" id="f-unit_id" name="unit_id"><option value="">—</option>
            <?php foreach ($units as $uid => $label): ?><option value="<?= (int) $uid ?>"<?= $val('unit_id') === (string) $uid ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?>
        </select><?= field_error($errors, 'unit_id') ?>
    </div>
    <div class="col-md-4"><?= $input('location', 'Location', $val('location'), 'text', false, 'maxlength="80"') ?></div>
    <div class="col-md-4"><?= $input('calibration_frequency_days', 'Calibration frequency (days)', $val('calibration_frequency_days'), 'number', false, 'min="1" max="3650"') ?></div>
    <?php if (! $isNew): ?>
    <div class="col-md-4">
        <label class="form-label required" for="f-status">Status</label>
        <select class="form-select<?= field_invalid($errors, 'status') ?>" id="f-status" name="status">
            <?php foreach (GAUGE_STATUSES as $k => $v): ?><option value="<?= $k ?>"<?= $val('status') === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach ?>
        </select><?= field_error($errors, 'status') ?>
    </div>
    <?php endif ?>
</div></div>
<?php if ($isNew): ?>
<div class="card mb-3"><div class="card-header">Current calibration (optional, for gauges already calibrated)</div><div class="card-body row g-3">
    <div class="col-md-3"><?= $input('calibration_date', 'Last calibration date', (string) old('calibration_date', ''), 'date') ?></div>
    <div class="col-md-3"><?= $input('due_date', 'Calibration due date', (string) old('due_date', ''), 'date') ?></div>
    <div class="col-md-3"><?= $input('certificate_no', 'Certificate no.', (string) old('certificate_no', ''), 'text', false, 'maxlength="60"') ?></div>
    <div class="col-md-3"><?= $input('agency', 'Agency', (string) old('agency', ''), 'text', false, 'maxlength="120"') ?></div>
</div></div>
<?php endif ?>
<button class="btn btn-primary btn-lg" type="submit" data-once><?= qms_icon('bi-check2') ?> <?= $isNew ? 'Register gauge' : 'Save' ?></button>
</form>
<?php require QMS_ROOT . '/includes/layout/footer.php';
