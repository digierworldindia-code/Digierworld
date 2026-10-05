<?php
/**
 * Inspection report search.
 */
require __DIR__ . '/includes/init.php';
$user = require_permission('inspection.view_own', 'inspection.view_all');
require_once QMS_ROOT . '/includes/inspections.php';
require_once QMS_ROOT . '/includes/masters.php';

$filters = [];
foreach (['from', 'to', 'type', 'part', 'machine', 'shift', 'status', 'result', 'q', 'superseded'] as $key) {
    $filters[$key] = get($key);
}
$paging   = paging(30);
$rows     = insp_search($filters, $paging, $user);
$types    = master_lookup('report-types');
$parts    = master_lookup('parts');
$machines = master_lookup('machines');
$shifts   = master_lookup('shifts');
$statuses = ['PENDING' => 'Waiting for approval'] + array_map(static fn (array $s): string => $s['label'], REPORT_STATUSES);
$select   = static function (string $name, string $label, array $options, string $all) use ($filters): string {
    $html = '<label class="form-label" for="f-' . $name . '">' . e($label) . '</label><select class="form-select" id="f-' . $name . '" name="' . $name . '"><option value="">' . e($all) . '</option>';
    foreach ($options as $value => $text) {
        $html .= '<option value="' . e((string) $value) . '"' . ($filters[$name] === (string) $value ? ' selected' : '') . '>' . e($text) . '</option>';
    }

    return $html . '</select>';
};

$page_title = 'Inspections';
require QMS_ROOT . '/includes/layout/header.php';
?>
<div class="qms-page-head">
    <div><h1>Inspections</h1><p class="qms-sub"><?= can('inspection.view_all') ? 'All inspection reports' : 'Reports you created, submitted or inspected' ?></p></div>
    <?php if (can('inspection.create')): ?><a class="btn btn-primary btn-lg" href="<?= url('inspection_new.php') ?>"><?= qms_icon('bi-plus-lg') ?> New inspection</a><?php endif ?>
</div>
<form class="qms-filter row g-2 align-items-end" method="get" action="<?= url('inspections.php') ?>">
    <div class="col-6 col-md-2"><label class="form-label" for="f-from">From</label><input class="form-control" type="date" id="f-from" name="from" value="<?= e($filters['from']) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label" for="f-to">To</label><input class="form-control" type="date" id="f-to" name="to" value="<?= e($filters['to']) ?>"></div>
    <div class="col-6 col-md-2"><?= $select('type', 'Report type', $types, 'All types') ?></div>
    <div class="col-6 col-md-3"><?= $select('part', 'Part', $parts, 'All parts') ?></div>
    <div class="col-6 col-md-3"><?= $select('machine', 'Machine', $machines, 'All machines') ?></div>
    <div class="col-6 col-md-2"><?= $select('shift', 'Shift', $shifts, 'All shifts') ?></div>
    <div class="col-6 col-md-2"><?= $select('status', 'Status', $statuses, 'Any (not superseded)') ?></div>
    <div class="col-6 col-md-2"><?= $select('result', 'Result', ['PASS' => 'PASS', 'FAIL' => 'Out of spec', 'INCOMPLETE' => 'Incomplete'], 'Any result') ?></div>
    <div class="col-6 col-md-3"><label class="form-label" for="f-q">Search</label><input class="form-control" id="f-q" name="q" placeholder="Report no., part, machine" value="<?= e($filters['q']) ?>"></div>
    <div class="col-6 col-md-1"><button class="btn btn-outline-primary w-100" type="submit"><?= qms_icon('bi-funnel') ?><span class="visually-hidden">Filter</span></button></div>
    <div class="col-12 col-md-2"><a class="btn btn-link w-100" href="<?= url('inspections.php') ?>">Clear</a></div>
</form>
<div class="card"><div class="qms-table-wrap">
<table class="table table-hover">
    <thead><tr><th>Report</th><th>Type</th><th>Date</th><th>Part</th><th>Machine</th><th>Shift</th><th>Operator</th><th>Result</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?><tr><td colspan="10" class="qms-empty"><?= qms_icon('bi-clipboard') ?>No inspection reports match.</td></tr><?php endif ?>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><strong><?= $r['report_no'] !== null ? e($r['report_no']) : '<span class="text-muted">Draft #' . (int) $r['id'] . '</span>' ?></strong>
                <?php if ((int) $r['revision_no'] > 0): ?><span class="qms-badge qms-badge--muted">Rev <?= (int) $r['revision_no'] ?></span><?php endif ?></td>
            <td><?= e($r['type_code']) ?></td>
            <td class="text-nowrap"><?= e(plant_date($r['inspection_date'])) ?></td>
            <td><?= e($r['part_number']) ?><div class="small text-muted"><?= e($r['part_name']) ?></div></td>
            <td><?= e($r['machine_code']) ?></td>
            <td><?= $r['layout'] === 'SHIFT_GRID' ? '<span class="small text-muted">' . (int) $r['round_count'] . ' insp.</span>' : e($r['shift_code'] ?? '') ?></td>
            <td><?= e($r['operator_name'] ?? $r['created_by_name'] ?? '') ?></td>
            <td><?= result_badge($r['overall_result']) ?><?= (int) $r['oos_count'] > 0 ? '<div class="small text-danger fw-bold">' . (int) $r['oos_count'] . ' OOS</div>' : '' ?></td>
            <td><?= status_badge($r['status']) ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('inspection.php?id=' . (int) $r['id']) ?>"><?= qms_icon('bi-eye') ?> Open</a></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div></div>
<?php require QMS_ROOT . '/includes/views/pager.php'; ?>
<?php require QMS_ROOT . '/includes/layout/footer.php';
