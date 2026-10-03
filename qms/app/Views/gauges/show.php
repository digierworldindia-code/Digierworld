<?php
/**
 * @var array<string, mixed> $gauge
 * @var array<string, mixed> $availability
 */
$a = $availability;
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="qms-page-head">
    <div><h1><?= esc($gauge['gauge_code']) ?> · <?= esc($gauge['gauge_name']) ?></h1>
        <p class="qms-sub"><?= esc($gauge['type_name']) ?> · <span class="qms-badge qms-badge--<?= $a['tone'] ?>"><?= esc($a['label']) ?></span></p></div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= site_url('gauges') ?>"><?= qms_icon('bi-arrow-left') ?> Gauges</a>
        <?php if (can('gauge.manage')): ?><a class="btn btn-outline-primary" href="<?= site_url('gauges/' . $gauge['id'] . '/edit') ?>"><?= qms_icon('bi-pencil') ?> Edit</a><?php endif ?>
    </div>
</div>
<div class="row g-3">
<div class="col-lg-5">
    <div class="card mb-3"><div class="card-header">Details</div><div class="card-body">
        <dl class="qms-dl">
            <dt>Status</dt><dd><?= esc(\App\Services\Gauges\GaugeService::STATUSES[$gauge['status']] ?? $gauge['status']) ?></dd>
            <dt>Make / serial</dt><dd><?= esc(trim(($gauge['make'] ?? '') . ' ' . ($gauge['serial_no'] ?? ''))) ?: '—' ?></dd>
            <dt>Range</dt><dd><?= esc($gauge['measuring_range'] ?? '—') ?></dd>
            <dt>Least count</dt><dd><?= $gauge['least_count'] !== null ? esc(rtrim(rtrim($gauge['least_count'], '0'), '.') . ' ' . ($gauge['unit_symbol'] ?? '')) : '—' ?></dd>
            <dt>Location</dt><dd><?= esc($gauge['location'] ?? '—') ?></dd>
            <dt>Frequency</dt><dd><?= $gauge['calibration_frequency_days'] !== null ? (int) $gauge['calibration_frequency_days'] . ' days' : '—' ?></dd>
            <dt>Last calibrated</dt><dd><?= esc(plant_date($gauge['last_calibration_date'])) ?: '—' ?></dd>
            <dt>Due</dt><dd><?= esc(plant_date($gauge['calibration_due_date'])) ?: '—' ?></dd>
        </dl>
    </div></div>
    <?php if (can('gauge.calibrate')): ?>
    <div class="card"><div class="card-header">Record calibration</div><div class="card-body">
        <form method="post" action="<?= site_url('gauges/' . $gauge['id'] . '/calibrations') ?>" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <div class="row g-2">
                <div class="col-6"><label class="form-label required" for="calibration_date">Calibration date</label>
                    <input class="form-control<?= field_invalid($errors, 'calibration_date') ?>" type="date" id="calibration_date" name="calibration_date" max="<?= esc($today, 'attr') ?>" value="<?= esc(old('calibration_date') ?? '', 'attr') ?>" required><?= field_error($errors, 'calibration_date') ?></div>
                <div class="col-6"><label class="form-label required" for="due_date">Next due date</label>
                    <input class="form-control<?= field_invalid($errors, 'due_date') ?>" type="date" id="due_date" name="due_date" value="<?= esc(old('due_date') ?? '', 'attr') ?>" required><?= field_error($errors, 'due_date') ?></div>
                <div class="col-6"><label class="form-label required" for="result">Result</label>
                    <select class="form-select" id="result" name="result">
                        <option value="ACCEPTED">Accepted</option><option value="ADJUSTED">Adjusted, then accepted</option><option value="REJECTED">Rejected (gauge goes on hold)</option>
                    </select></div>
                <div class="col-6"><label class="form-label" for="certificate_no">Certificate no.</label><input class="form-control" id="certificate_no" name="certificate_no" maxlength="60"></div>
                <div class="col-12"><label class="form-label" for="agency">Agency</label><input class="form-control" id="agency" name="agency" maxlength="120"></div>
                <div class="col-12"><label class="form-label" for="certificate">Certificate (PDF / image, max <?= (int) (config('Qms')->certificateMaxKb / 1024) ?> MB)</label>
                    <input class="form-control" type="file" id="certificate" name="certificate" accept="application/pdf,image/png,image/jpeg"></div>
                <div class="col-12"><label class="form-label" for="remarks">Remarks</label><input class="form-control" id="remarks" name="remarks" maxlength="500"></div>
            </div>
            <button class="btn btn-primary mt-3" type="submit" data-once><?= qms_icon('bi-check2-circle') ?> Record calibration</button>
            <div class="form-text">Calibration records cannot be edited afterwards. To correct one, record a new entry.</div>
        </form>
    </div></div>
    <?php endif ?>
</div>
<div class="col-lg-7">
    <div class="card mb-3"><div class="card-header">Calibration history</div><div class="qms-table-wrap">
    <table class="table">
        <thead><tr><th>Calibrated</th><th>Due</th><th>Result</th><th>Certificate</th><th>Recorded</th></tr></thead>
        <tbody>
        <?php if ($calibrations === []): ?><tr><td colspan="5" class="qms-empty">No calibration recorded yet.</td></tr><?php endif ?>
        <?php foreach ($calibrations as $c): ?>
            <tr>
                <td class="num"><?= esc(plant_date($c['calibration_date'])) ?></td>
                <td class="num"><?= esc(plant_date($c['due_date'])) ?></td>
                <td><span class="qms-badge qms-badge--<?= $c['result'] === 'REJECTED' ? 'fail' : 'pass' ?>"><?= esc($c['result']) ?></span></td>
                <td class="small"><?= esc($c['certificate_no'] ?? '') ?> <?= esc($c['agency'] ?? '') ?>
                    <?php if ($c['certificate_file'] !== null): ?><br><a href="<?= site_url('gauges/calibrations/' . $c['id'] . '/certificate') ?>"><?= qms_icon('bi-download') ?> Download</a><?php endif ?></td>
                <td class="small text-muted"><?= esc($c['recorded_by'] ?? '') ?><br><?= esc(plant_dt($c['created_at'])) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div></div>
    <div class="card"><div class="card-header">Where used (latest 50)</div><div class="qms-table-wrap">
    <table class="table">
        <thead><tr><th>Report</th><th>Date</th><th>Part / machine</th><th>Parameter</th><th>Calibration at use</th></tr></thead>
        <tbody>
        <?php if ($usage === []): ?><tr><td colspan="5" class="qms-empty">Not used in any inspection yet.</td></tr><?php endif ?>
        <?php foreach ($usage as $u): ?>
            <tr>
                <td><a href="<?= site_url('inspections/' . $u['id']) ?>"><?= esc($u['report_no'] ?? 'Draft #' . $u['id']) ?><?= (int) $u['revision_no'] > 0 ? ' R' . (int) $u['revision_no'] : '' ?></a></td>
                <td class="num"><?= esc(plant_date($u['inspection_date'])) ?></td>
                <td><?= esc($u['part_number']) ?> / <?= esc($u['machine_code']) ?></td>
                <td><?= esc($u['parameter']) ?></td>
                <td><?= (int) $u['gauge_cal_valid'] === 1 ? '<span class="qms-badge qms-badge--pass">valid</span>' : '<span class="qms-badge qms-badge--fail">expired</span>' ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div></div>
</div>
</div>
<?= $this->endSection() ?>
