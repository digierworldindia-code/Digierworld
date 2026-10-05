<?php
/**
 * One gauge: details, calibration history, where used, and "Record calibration".
 */
require __DIR__ . '/includes/init.php';
$user = require_permission('gauge.view');
require_once QMS_ROOT . '/includes/gauges.php';

$id = get_int('id');

if (is_post()) {
    require_permission('gauge.calibrate');
    try {
        gauge_record_calibration($id, post_fields(['calibration_date', 'due_date', 'result', 'agency', 'certificate_no', 'remarks']),
            $_FILES['certificate'] ?? null, $user);
    } catch (Throwable $e) {
        back_with_error($e, 'gauge.php?id=' . $id);
    }
    done('Calibration recorded.', 'gauge.php?id=' . $id);
}

$gauge        = gauge_find($id);
$today        = production_current()['date'];
$a            = gauge_availability($gauge, $today);
$calibrations = gauge_calibrations($id);
$usage        = gauge_where_used($id, null, 50);
$errors       = form_errors();

$page_title = 'Gauge ' . $gauge['gauge_code'];
require QMS_ROOT . '/includes/layout/header.php';
?>
<div class="qms-page-head">
    <div><h1><?= e($gauge['gauge_code']) ?> · <?= e($gauge['gauge_name']) ?></h1>
        <p class="qms-sub"><?= e($gauge['type_name']) ?> · <span class="qms-badge qms-badge--<?= e($a['tone']) ?>"><?= e($a['label']) ?></span></p></div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= url('gauges.php') ?>"><?= qms_icon('bi-arrow-left') ?> Gauges</a>
        <?php if (can('gauge.manage')): ?><a class="btn btn-outline-primary" href="<?= url('gauge_edit.php?id=' . (int) $gauge['id']) ?>"><?= qms_icon('bi-pencil') ?> Edit</a><?php endif ?>
    </div>
</div>
<div class="row g-3">
<div class="col-lg-5">
    <div class="card mb-3"><div class="card-header">Details</div><div class="card-body">
        <dl class="qms-dl">
            <dt>Status</dt><dd><?= e(GAUGE_STATUSES[$gauge['status']] ?? $gauge['status']) ?></dd>
            <dt>Make / serial</dt><dd><?= e(trim(($gauge['make'] ?? '') . ' ' . ($gauge['serial_no'] ?? ''))) ?: '—' ?></dd>
            <dt>Range</dt><dd><?= e($gauge['measuring_range'] ?? '—') ?></dd>
            <dt>Least count</dt><dd><?= $gauge['least_count'] !== null ? e(gauge_least_count($gauge)) : '—' ?></dd>
            <dt>Location</dt><dd><?= e($gauge['location'] ?? '—') ?></dd>
            <dt>Frequency</dt><dd><?= $gauge['calibration_frequency_days'] !== null ? (int) $gauge['calibration_frequency_days'] . ' days' : '—' ?></dd>
            <dt>Last calibrated</dt><dd><?= e(plant_date($gauge['last_calibration_date'])) ?: '—' ?></dd>
            <dt>Due</dt><dd><?= e(plant_date($gauge['calibration_due_date'])) ?: '—' ?></dd>
        </dl>
    </div></div>
    <?php if (can('gauge.calibrate')): ?>
    <div class="card"><div class="card-header">Record calibration</div><div class="card-body">
        <form method="post" action="<?= url('gauge.php?id=' . (int) $gauge['id']) ?>" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <div class="row g-2">
                <div class="col-6"><label class="form-label required" for="calibration_date">Calibration date</label>
                    <input class="form-control<?= field_invalid($errors, 'calibration_date') ?>" type="date" id="calibration_date" name="calibration_date" max="<?= e($today) ?>" value="<?= e((string) old('calibration_date', '')) ?>" required><?= field_error($errors, 'calibration_date') ?></div>
                <div class="col-6"><label class="form-label required" for="due_date">Next due date</label>
                    <input class="form-control<?= field_invalid($errors, 'due_date') ?>" type="date" id="due_date" name="due_date" value="<?= e((string) old('due_date', '')) ?>" required><?= field_error($errors, 'due_date') ?></div>
                <div class="col-6"><label class="form-label required" for="result">Result</label>
                    <select class="form-select<?= field_invalid($errors, 'result') ?>" id="result" name="result">
                        <?php foreach (CALIBRATION_RESULTS as $k => $v): ?><option value="<?= $k ?>"<?= old('result') === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach ?>
                    </select><?= field_error($errors, 'result') ?></div>
                <div class="col-6"><label class="form-label" for="certificate_no">Certificate no.</label><input class="form-control<?= field_invalid($errors, 'certificate_no') ?>" id="certificate_no" name="certificate_no" maxlength="60" value="<?= e((string) old('certificate_no', '')) ?>"><?= field_error($errors, 'certificate_no') ?></div>
                <div class="col-12"><label class="form-label" for="agency">Agency</label><input class="form-control<?= field_invalid($errors, 'agency') ?>" id="agency" name="agency" maxlength="120" value="<?= e((string) old('agency', '')) ?>"><?= field_error($errors, 'agency') ?></div>
                <div class="col-12"><label class="form-label" for="certificate">Certificate (PDF / image, max <?= (int) (UPLOAD_CERTIFICATE_MAX_KB / 1024) ?> MB)</label>
                    <input class="form-control<?= field_invalid($errors, 'certificate') ?>" type="file" id="certificate" name="certificate" accept="application/pdf,image/png,image/jpeg"><?= field_error($errors, 'certificate') ?></div>
                <div class="col-12"><label class="form-label" for="remarks">Remarks</label><input class="form-control<?= field_invalid($errors, 'remarks') ?>" id="remarks" name="remarks" maxlength="500" value="<?= e((string) old('remarks', '')) ?>"><?= field_error($errors, 'remarks') ?></div>
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
                <td class="num"><?= e(plant_date($c['calibration_date'])) ?></td>
                <td class="num"><?= e(plant_date($c['due_date'])) ?></td>
                <td><span class="qms-badge qms-badge--<?= $c['result'] === 'REJECTED' ? 'fail' : 'pass' ?>"><?= e($c['result']) ?></span></td>
                <td class="small"><?= e($c['certificate_no'] ?? '') ?> <?= e($c['agency'] ?? '') ?>
                    <?php if ($c['certificate_file'] !== null): ?><br><a href="<?= url('certificate.php?id=' . (int) $c['id']) ?>"><?= qms_icon('bi-download') ?> Download</a><?php endif ?></td>
                <td class="small text-muted"><?= e($c['recorded_by'] ?? '') ?><br><?= e(plant_dt($c['created_at'])) ?></td>
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
                <td><a href="<?= url('inspection.php?id=' . (int) $u['id']) ?>"><?= e($u['report_no'] ?? 'Draft #' . $u['id']) ?><?= (int) $u['revision_no'] > 0 ? ' R' . (int) $u['revision_no'] : '' ?></a></td>
                <td class="num"><?= e(plant_date($u['inspection_date'])) ?></td>
                <td><?= e($u['part_number']) ?> / <?= e($u['machine_code']) ?></td>
                <td><?= e($u['parameter']) ?></td>
                <td><?= (int) $u['gauge_cal_valid'] === 1 ? '<span class="qms-badge qms-badge--pass">valid</span>' : '<span class="qms-badge qms-badge--fail">expired</span>' ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div></div>
</div>
</div>
<?php require QMS_ROOT . '/includes/layout/footer.php';
