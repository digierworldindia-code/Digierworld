<?php
/**
 * Inspection report search.
 *
 * @var list<array<string, mixed>> $rows
 * @var array<string, string>      $filters
 * @var \App\Libraries\Paging      $paging
 * @var array<int, string>         $types
 * @var array<int, string>         $parts
 * @var array<int, string>         $machines
 * @var array<int, string>         $shifts
 */
$statuses = ['PENDING' => 'Waiting for approval'];
foreach (\App\Enums\ReportStatus::cases() as $case) {
    $statuses[$case->value] = $case->label();
}
$select = static function (string $name, string $label, array $options, array $filters, string $all): string {
    $html = '<label class="form-label" for="f-' . $name . '">' . esc($label) . '</label><select class="form-select" id="f-' . $name . '" name="' . $name . '"><option value="">' . esc($all) . '</option>';
    foreach ($options as $value => $text) {
        $html .= '<option value="' . esc((string) $value, 'attr') . '"' . ((string) ($filters[$name] ?? '') === (string) $value ? ' selected' : '') . '>' . esc($text) . '</option>';
    }

    return $html . '</select>';
};
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="qms-page-head">
    <div><h1>Inspections</h1><p class="qms-sub"><?= can('inspection.view_all') ? 'All inspection reports' : 'Reports you created, submitted or inspected' ?></p></div>
    <?php if (can('inspection.create')): ?><a class="btn btn-primary btn-lg" href="<?= site_url('inspections/new') ?>"><?= qms_icon('bi-plus-lg') ?> New inspection</a><?php endif ?>
</div>
<form class="qms-filter row g-2 align-items-end" method="get">
    <div class="col-6 col-md-2"><label class="form-label" for="f-from">From</label><input class="form-control" type="date" id="f-from" name="from" value="<?= esc($filters['from'] ?? '', 'attr') ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label" for="f-to">To</label><input class="form-control" type="date" id="f-to" name="to" value="<?= esc($filters['to'] ?? '', 'attr') ?>"></div>
    <div class="col-6 col-md-2"><?= $select('type', 'Report type', $types, $filters, 'All types') ?></div>
    <div class="col-6 col-md-3"><?= $select('part', 'Part', $parts, $filters, 'All parts') ?></div>
    <div class="col-6 col-md-3"><?= $select('machine', 'Machine', $machines, $filters, 'All machines') ?></div>
    <div class="col-6 col-md-2"><?= $select('shift', 'Shift', $shifts, $filters, 'All shifts') ?></div>
    <div class="col-6 col-md-2"><?= $select('status', 'Status', $statuses, $filters, 'Any (not superseded)') ?></div>
    <div class="col-6 col-md-2"><?= $select('result', 'Result', ['PASS' => 'PASS', 'FAIL' => 'Out of spec', 'INCOMPLETE' => 'Incomplete'], $filters, 'Any result') ?></div>
    <div class="col-6 col-md-3"><label class="form-label" for="f-q">Search</label><input class="form-control" id="f-q" name="q" placeholder="Report no., part, machine" value="<?= esc($filters['q'] ?? '', 'attr') ?>"></div>
    <div class="col-6 col-md-1"><button class="btn btn-outline-primary w-100" type="submit"><?= qms_icon('bi-funnel') ?><span class="visually-hidden">Filter</span></button></div>
    <div class="col-12 col-md-2"><a class="btn btn-link w-100" href="<?= site_url('inspections') ?>">Clear</a></div>
</form>
<div class="card"><div class="qms-table-wrap">
<table class="table table-hover">
    <thead><tr><th>Report</th><th>Type</th><th>Date</th><th>Part</th><th>Machine</th><th>Shift</th><th>Operator</th><th>Result</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?><tr><td colspan="10" class="qms-empty"><?= qms_icon('bi-clipboard') ?>No inspection reports match.</td></tr><?php endif ?>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><strong><?= $r['report_no'] !== null ? esc($r['report_no']) : '<span class="text-muted">Draft #' . (int) $r['id'] . '</span>' ?></strong>
                <?php if ((int) $r['revision_no'] > 0): ?><span class="qms-badge qms-badge--muted">Rev <?= (int) $r['revision_no'] ?></span><?php endif ?></td>
            <td><?= esc($r['type_code']) ?></td>
            <td class="text-nowrap"><?= esc(plant_date($r['inspection_date'])) ?></td>
            <td><?= esc($r['part_number']) ?><div class="small text-muted"><?= esc($r['part_name']) ?></div></td>
            <td><?= esc($r['machine_code']) ?></td>
            <td><?= $r['layout'] === 'SHIFT_GRID' ? '<span class="small text-muted">' . (int) $r['round_count'] . ' insp.</span>' : esc($r['shift_code'] ?? '') ?></td>
            <td><?= esc($r['operator_name'] ?? $r['created_by_name'] ?? '') ?></td>
            <td><?= result_badge($r['overall_result']) ?><?= (int) $r['oos_count'] > 0 ? '<div class="small text-danger fw-bold">' . (int) $r['oos_count'] . ' OOS</div>' : '' ?></td>
            <td><?= status_badge($r['status']) ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= site_url('inspections/' . $r['id']) ?>"><?= qms_icon('bi-eye') ?> Open</a></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div></div>
<?= view('partials/pager', ['paging' => $paging]) ?>
<?= $this->endSection() ?>
