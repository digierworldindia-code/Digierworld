<?php
/**
 * Start an inspection: report type → part → machine → date / shift.
 * Works without JavaScript; new-inspection.js narrows the machine list to the
 * machines a published template applies to.
 *
 * @var array<string, mixed> $options
 * @var int                  $selectedType
 * @var string               $clientUuid
 * @var array<string, string> $errors
 */
$types    = array_values(array_filter($options['reportTypes'], static fn (array $t): bool => (int) $t['template_count'] > 0));
$current  = $options['current'];
$machines = service('masterData')->lookup('machines');
$selType  = $selectedType ?: (count($types) === 1 ? (int) $types[0]['id'] : 0);
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="qms-page-head">
    <div><h1>New inspection</h1><p class="qms-sub">The inspection sheet is loaded automatically from the published template for the part and machine.</p></div>
</div>
<?php if ($types === []): ?>
    <div class="alert alert-warning"><?= qms_icon('bi-exclamation-triangle') ?> No published inspection templates yet. Ask the QA Admin to publish one (Quality setup → Templates).</div>
<?php else: ?>
<form method="post" action="<?= site_url('inspections') ?>" id="newInspection" class="qms-wizard" data-options-url="<?= site_url('inspections/options') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="client_uuid" value="<?= esc($clientUuid, 'attr') ?>">

    <div class="card mb-3"><div class="card-body">
        <h2 class="h5"><span class="qms-step-no">1</span> Report type</h2>
        <div class="qms-option-grid" role="radiogroup" aria-label="Report type">
            <?php foreach ($types as $type): ?>
                <input class="btn-check" type="radio" name="report_type_id" id="type-<?= (int) $type['id'] ?>" value="<?= (int) $type['id'] ?>"
                       data-shift="<?= esc($type['header_shift'], 'attr') ?>" data-layout="<?= esc($type['layout'], 'attr') ?>" required<?= $selType === (int) $type['id'] ? ' checked' : '' ?>>
                <label class="btn btn-outline-primary btn-lg" for="type-<?= (int) $type['id'] ?>">
                    <strong><?= esc($type['code']) ?></strong>&nbsp;<?= esc($type['name']) ?>
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
                        <option value="<?= (int) $part['id'] ?>"<?= (string) old('part_id') === (string) $part['id'] ? ' selected' : '' ?>><?= esc($part['part_number'] . ' · ' . $part['part_name']) ?></option>
                    <?php endforeach ?>
                </select><?= field_error($errors, 'part_id') ?></div>
        </div>
    </div></div>

    <div class="card mb-3"><div class="card-body">
        <h2 class="h5"><span class="qms-step-no">3</span> Machine</h2>
        <div id="machineHint" class="text-muted mb-2" aria-live="polite">Choose the report type and part to see the machines with an inspection template.</div>
        <select class="form-select form-select-lg<?= field_invalid($errors, 'machine_id') ?>" id="machine_id" name="machine_id" required aria-describedby="machineHint">
            <option value="">Choose the machine</option>
            <?php foreach ($machines as $id => $label): ?>
                <option value="<?= (int) $id ?>"<?= (string) old('machine_id') === (string) $id ? ' selected' : '' ?>><?= esc($label) ?></option>
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
                        <option value="<?= esc($date, 'attr') ?>"<?= (old('inspection_date') ?? $current['date']) === $date ? ' selected' : '' ?>><?= esc(plant_date($date)) ?><?= $i === 0 ? ' (today)' : '' ?></option>
                    <?php endforeach ?>
                </select><?= field_error($errors, 'inspection_date') ?></div>
            <div class="col-md-8" id="shiftBlock">
                <span class="form-label d-block" id="shiftLabel">Shift</span>
                <div class="qms-choice" role="radiogroup" aria-labelledby="shiftLabel">
                    <?php foreach ($options['shifts'] as $shift): ?>
                        <?php $checked = (string) (old('shift_id') ?? ($current['shift']['id'] ?? '')) === (string) $shift['id']; ?>
                        <input class="btn-check" type="radio" name="shift_id" id="shift-<?= (int) $shift['id'] ?>" value="<?= (int) $shift['id'] ?>"<?= $checked ? ' checked' : '' ?>>
                        <label class="btn btn-outline-primary" for="shift-<?= (int) $shift['id'] ?>">Shift <?= esc($shift['code']) ?><br><small><?= esc(substr($shift['start_time'], 0, 5) . '–' . substr($shift['end_time'], 0, 5)) ?></small></label>
                    <?php endforeach ?>
                </div>
                <?= field_error($errors, 'shift_id') ?>
                <div class="form-text" id="gridHint" hidden>In-Process sheets cover all shifts of the production date; each inspection time records its shift.</div>
            </div>
        </div>
    </div></div>

    <div class="qms-actionbar">
        <span class="qms-progress">Template, LSL and USL are applied automatically.</span>
        <a class="btn btn-outline-secondary btn-lg" href="<?= site_url('/') ?>">Cancel</a>
        <button class="btn btn-primary btn-lg" type="submit" data-once><?= qms_icon('bi-play-fill') ?> Start inspection</button>
    </div>
</form>
<?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script type="module" src="<?= qms_asset('assets/js/new-inspection.js') ?>"></script>
<?= $this->endSection() ?>
