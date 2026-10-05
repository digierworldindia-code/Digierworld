<?php
/**
 * Gauge list with calibration status.
 */
require __DIR__ . '/includes/init.php';
$user = require_permission('gauge.view');
require_once QMS_ROOT . '/includes/gauges.php';
require_once QMS_ROOT . '/includes/masters.php';

$filters = ['q' => get('q'), 'type' => get('type'), 'status' => get('status'), 'due' => get('due')];
$paging  = paging(30);
$gauges  = gauge_list($filters, $paging);
$types   = master_lookup('gauge-types');

$page_title = 'Gauges';
require QMS_ROOT . '/includes/layout/header.php';
?>
<div class="qms-page-head">
    <div><h1>Gauges</h1><p class="qms-sub">Instruments, calibration status and history. Expired gauges can block inspection submission (Settings → Gauges).</p></div>
    <?php if (can('gauge.manage')): ?><a class="btn btn-primary btn-lg" href="<?= url('gauge_edit.php') ?>"><?= qms_icon('bi-plus-lg') ?> Register gauge</a><?php endif ?>
</div>
<form class="qms-filter row g-2 align-items-end" method="get" action="<?= url('gauges.php') ?>">
    <div class="col-md-3"><label class="form-label" for="q">Search</label><input class="form-control" id="q" name="q" placeholder="Gauge ID, name, serial" value="<?= e($filters['q']) ?>"></div>
    <div class="col-md-3"><label class="form-label" for="type">Type</label>
        <select class="form-select" id="type" name="type"><option value="">All types</option>
        <?php foreach ($types as $id => $label): ?><option value="<?= (int) $id ?>"<?= $filters['type'] === (string) $id ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></div>
    <div class="col-md-2"><label class="form-label" for="status">Status</label>
        <select class="form-select" id="status" name="status"><option value="">In use (not retired)</option>
        <?php foreach (GAUGE_STATUSES as $k => $v): ?><option value="<?= $k ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach ?></select></div>
    <div class="col-md-2"><label class="form-label" for="due">Calibration</label>
        <select class="form-select" id="due" name="due"><option value="">Any</option>
            <option value="soon"<?= $filters['due'] === 'soon' ? ' selected' : '' ?>>Due soon</option>
            <option value="expired"<?= $filters['due'] === 'expired' ? ' selected' : '' ?>>Expired</option></select></div>
    <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit"><?= qms_icon('bi-funnel') ?> Filter</button></div>
</form>
<div class="card"><div class="qms-table-wrap">
<table class="table table-hover">
    <thead><tr><th>Gauge ID</th><th>Name</th><th>Type</th><th>Range / LC</th><th>Calibration</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if ($gauges === []): ?><tr><td colspan="7" class="qms-empty"><?= qms_icon('bi-rulers') ?>No gauges match.</td></tr><?php endif ?>
    <?php foreach ($gauges as $g): ?>
        <tr>
            <td><strong><?= e($g['gauge_code']) ?></strong></td>
            <td><?= e($g['gauge_name']) ?></td>
            <td><?= e($g['type_name']) ?></td>
            <td class="small"><?= e($g['measuring_range'] ?? '') ?><?= $g['least_count'] !== null ? '<br>LC ' . e(gauge_least_count($g)) : '' ?></td>
            <td><?= gauge_badge($g['availability']) ?></td>
            <td><?= e(GAUGE_STATUSES[$g['status']] ?? $g['status']) ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('gauge.php?id=' . (int) $g['id']) ?>"><?= qms_icon('bi-eye') ?> Open</a></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div></div>
<?php require QMS_ROOT . '/includes/views/pager.php'; ?>
<?php require QMS_ROOT . '/includes/layout/footer.php';
