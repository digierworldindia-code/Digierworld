<?php
/**
 * Start an inspection: report type → part → machine → date / shift.
 * Works without JavaScript; new-inspection.js narrows the machine list to the
 * machines a published template applies to (api/machine_options.php).
 */
require __DIR__ . '/includes/init.php';
$user = require_permission('inspection.create');
require_once QMS_ROOT . '/includes/inspections.php';
require_once QMS_ROOT . '/includes/masters.php';

if (is_post()) {
    try {
        $id = insp_start(post_fields(['client_uuid', 'report_type_id', 'part_id', 'machine_id', 'inspection_date', 'shift_id']), $user);
    } catch (Throwable $e) {
        back_with_error($e, 'inspection_new.php' . (ctype_digit(post('report_type_id')) ? '?type=' . post('report_type_id') : ''));
    }
    redirect('inspection_edit.php?id=' . $id);
}

$options  = insp_wizard_options();
$types    = array_values(array_filter($options['reportTypes'], static fn (array $t): bool => (int) $t['template_count'] > 0));
$current  = $options['current'];
$machines = master_lookup('machines');
$errors   = form_errors();
$selType  = (int) (old('report_type_id') ?? get_int('type'));
if ($selType === 0 && count($types) === 1) {
    $selType = (int) $types[0]['id'];
}

$page_title   = 'New inspection';
$page_scripts = ['new-inspection.js'];
require QMS_ROOT . '/includes/layout/header.php';
?>
<div class="qms-page-head">
    <div><h1>New inspection</h1><p class="qms-sub">The inspection sheet is loaded automatically from the published template for the part and machine.</p></div>
</div>
<?php if ($types === []): ?>
    <div class="alert alert-warning"><?= qms_icon('bi-exclamation-triangle') ?> No published inspection templates yet. Ask the QA Admin to publish one (Quality setup → Templates).</div>
<?php else: ?>
<form method="post" action="<?= url('inspection_new.php') ?>" id="newInspection" class="qms-wizard" data-options-url="<?= url('api/machine_options.php') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="client_uuid" value="<?= e((string) (old('client_uuid') ?? uuid_v4())) ?>">

    <div class="card mb-3"><div class="card-body">
        <h2 class="h5"><span class="qms-step-no">1</span> Report type</h2>
        <div class="qms-option-grid" role="radiogroup" aria-label="Report type">
            <?php foreach ($types as $type): ?>
                <input class="btn-check" type="radio" name="report_type_id" id="type-<?= (int) $type['id'] ?>" value="<?= (int) $type['id'] ?>"
                       data-shift="<?= e($type['header_shift']) ?>" data-layout="<?= e($type['layout']) ?>" required<?= $selType === (int) $type['id'] ? ' checked' : '' ?>>
                <label class="btn btn-outline-primary btn-lg" for="type-<?= (int) $type['id'] ?>">
                    <strong><?= e($type['code']) ?></strong>&nbsp;<?= e($type['name']) ?>
                </label>
            <?php endforeach ?>
        </div>
        <?= field_error($errors, 'report_type_id') ?>
    </div></div>

    <div class="card mb-3"><div class="card-body">
        <h2 class="h5"><span class="qms-step-no">2</span> Part</h2>
        <div class="row g-2">
            <div class="col-md-4"><label class="form-label" for="partFilter">Search part</label>
                <input class="form-control form-control-lg" id="partFilter" type="search" placeholder="Type part number or name" autocomplete="off"></div>
            <div class="col-md-8"><label class="form-label required" for="part_id">Part number</label>
                <select class="form-select form-select-lg<?= field_invalid($errors, 'part_id') ?>" id="part_id" name="part_id" required>
                    <option value="">Choose the part</option>
                    <?php foreach ($options['parts'] as $part): ?>
                        <option value="<?= (int) $part['id'] ?>"<?= (string) old('part_id') === (string) $part['id'] ? ' selected' : '' ?>><?= e($part['part_number'] . ' · ' . $part['part_name']) ?></option>
                    <?php endforeach ?>
                </select><?= field_error($errors, 'part_id') ?></div>
        </div>
    </div></div>

    <div class="card mb-3"><div class="card-body">
        <h2 class="h5"><span class="qms-step-no">3</span> Machine</h2>
        <div id="machineHint" class="text-muted mb-2" aria-live="polite">Choose the report type and part to see the machines with an inspection template.</div>
        <select class="form-select form-select-lg<?= field_invalid($errors, 'machine_id') ?>" id="machine_id" name="machine_id" required aria-describedby="machineHint">
            <option value="">Choose the machine</option>
            <?php foreach ($machines as $mid => $label): ?>
                <option value="<?= (int) $mid ?>"<?= (string) old('machine_id') === (string) $mid ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach ?>
        </select><?= field_error($errors, 'machine_id') ?>
        <?= field_error($errors, 'template') ?>
    </div></div>

    <div class="card mb-3"><div class="card-body">
        <h2 class="h5"><span class="qms-step-no">4</span> Date and shift</h2>
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label required" for="inspection_date">Production date</label>
                <select class="form-select form-select-lg<?= field_invalid($errors, 'inspection_date') ?>" id="inspection_date" name="inspection_date" required>
                    <?php foreach ($options['dates'] as $i => $date): ?>
                        <option value="<?= e($date) ?>"<?= (old('inspection_date') ?? $current['date']) === $date ? ' selected' : '' ?>><?= e(plant_date($date)) ?><?= $i === 0 ? ' (today)' : '' ?></option>
                    <?php endforeach ?>
                </select><?= field_error($errors, 'inspection_date') ?></div>
            <div class="col-md-8" id="shiftBlock">
                <span class="form-label d-block" id="shiftLabel">Shift</span>
                <div class="qms-choice" role="radiogroup" aria-labelledby="shiftLabel">
                    <?php foreach ($options['shifts'] as $shift): ?>
                        <?php $checked = (string) (old('shift_id') ?? ($current['shift']['id'] ?? '')) === (string) $shift['id']; ?>
                        <input class="btn-check" type="radio" name="shift_id" id="shift-<?= (int) $shift['id'] ?>" value="<?= (int) $shift['id'] ?>"<?= $checked ? ' checked' : '' ?>>
                        <label class="btn btn-outline-primary" for="shift-<?= (int) $shift['id'] ?>">Shift <?= e($shift['code']) ?><br><small><?= e(substr($shift['start_time'], 0, 5) . '–' . substr($shift['end_time'], 0, 5)) ?></small></label>
                    <?php endforeach ?>
                </div>
                <?= field_error($errors, 'shift_id') ?>
                <div class="form-text" id="gridHint" hidden>In-Process sheets cover all shifts of the production date; each inspection time records its shift.</div>
            </div>
        </div>
    </div></div>

    <div class="qms-actionbar">
        <span class="qms-progress">Template, LSL and USL are applied automatically.</span>
        <a class="btn btn-outline-secondary btn-lg" href="<?= url('index.php') ?>">Cancel</a>
        <button class="btn btn-primary btn-lg" type="submit" data-once><?= qms_icon('bi-play-fill') ?> Start inspection</button>
    </div>
</form>
<?php endif ?>
<?php require QMS_ROOT . '/includes/layout/footer.php';
