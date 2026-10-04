<?php
/**
 * Approval inbox: reports waiting for a stage the user may sign.
 *
 * @var list<array<string, mixed>> $rows
 * @var array<string, string>      $filters
 * @var \App\Libraries\Paging      $paging
 * @var array<int, string>         $types
 */
use App\Enums\ReportStatus;
use App\Enums\WorkflowStage;
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="qms-page-head">
    <div><h1>Approvals</h1><p class="qms-sub">Reports waiting for your verification or approval, oldest first.</p></div>
</div>
<form class="qms-filter row g-2 align-items-end" method="get">
    <div class="col-md-4"><label class="form-label" for="q">Search</label><input class="form-control" id="q" name="q" placeholder="Report no., part or machine" value="<?= esc($filters['q'], 'attr') ?>"></div>
    <div class="col-md-3"><label class="form-label" for="type">Report type</label>
        <select class="form-select" id="type" name="type"><option value="">All types</option>
            <?php foreach ($types as $tid => $label): ?><option value="<?= (int) $tid ?>"<?= $filters['type'] === (string) $tid ? ' selected' : '' ?>><?= esc($label) ?></option><?php endforeach ?>
        </select></div>
    <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit"><?= qms_icon('bi-funnel') ?> Filter</button></div>
</form>
<div class="card"><div class="qms-table-wrap">
<table class="table table-hover">
    <thead><tr><th>Report</th><th>Waiting for</th><th>Part / machine</th><th>Date</th><th>Submitted by</th><th>Result</th><th></th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?><tr><td colspan="7" class="qms-empty"><?= qms_icon('bi-inbox') ?>Nothing is waiting for you.</td></tr><?php endif ?>
    <?php foreach ($rows as $r): $stage = WorkflowStage::forPendingStatus(ReportStatus::from($r['status'])); ?>
        <tr>
            <td><strong><?= esc((string) $r['report_no']) ?></strong><?= (int) $r['revision_no'] > 0 ? ' <span class="qms-badge qms-badge--muted">Rev ' . (int) $r['revision_no'] . '</span>' : '' ?>
                <div class="small text-muted"><?= esc($r['type_name']) ?></div></td>
            <td><?= status_badge($r['status']) ?>
                <?php if ($r['age_hours'] >= 24): ?><div class="small text-danger fw-bold"><?= qms_icon('bi-clock-history') ?> waiting <?= (int) floor($r['age_hours'] / 24) ?> day(s)</div><?php endif ?></td>
            <td><?= esc($r['part_number']) ?> · <?= esc($r['machine_code']) ?></td>
            <td class="text-nowrap"><?= esc(plant_date($r['inspection_date'])) ?><?= $r['shift_code'] !== null ? ' · ' . esc($r['shift_code']) : '' ?></td>
            <td><?= esc((string) ($r['submitted_by_full'] ?? $r['submitted_by_name'])) ?><div class="small text-muted"><?= esc(plant_dt($r['submitted_at'])) ?></div></td>
            <td><?= result_badge($r['overall_result']) ?><?= (int) $r['oos_count'] > 0 ? '<div class="small text-danger fw-bold">' . (int) $r['oos_count'] . ' OOS</div>' : '' ?></td>
            <td class="text-end">
                <?php if ($r['blocked'] !== null): ?><div class="small text-muted mb-1"><?= qms_icon('bi-person-x') ?> <?= esc($r['blocked']) ?></div><?php endif ?>
                <a class="btn btn-sm <?= $r['blocked'] === null ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= site_url('inspections/' . $r['id']) ?>"><?= qms_icon('bi-eye') ?> <?= $r['blocked'] === null ? 'Review' : 'View' ?></a>
            </td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div></div>
<?= view('partials/pager', ['paging' => $paging]) ?>
<?= $this->endSection() ?>
