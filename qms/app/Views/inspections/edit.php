<?php
/**
 * Inspection entry screen (tablet first). Autosave, offline queue and the live
 * PASS / FAIL display are handled by inspection-form.js; every value is
 * validated and evaluated again on the server.
 *
 * @var array<string, mixed>       $report
 * @var list<array<string, mixed>> $structure
 * @var list<array<string, mixed>> $rounds
 * @var array<string, mixed>       $actions
 * @var list<array<string, mixed>> $approvals
 * @var list<string>               $problems
 * @var int                        $autosave
 */
$returned = null;
foreach (array_reverse($approvals) as $a) {
    if ($a['action'] === 'RETURNED') {
        $returned = $a;
        break;
    }
}
$isGrid = $report['layout'] === 'SHIFT_GRID';
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div id="inspectionForm" class="qms-entry<?= $isGrid ? ' is-grid' : '' ?>"
     data-report="<?= (int) $report['id'] ?>" data-lock="<?= (int) $report['lock_version'] ?>" data-status="<?= esc($report['status'], 'attr') ?>"
     data-layout="<?= esc($report['layout'], 'attr') ?>" data-inspection-date="<?= esc($report['inspection_date'], 'attr') ?>"
     data-save-url="<?= site_url('inspections/' . $report['id'] . '/draft') ?>" data-submit-url="<?= site_url('inspections/' . $report['id'] . '/submit') ?>"
     data-autosave="<?= (int) $autosave ?>" data-user="<?= (int) $user['id'] ?>" data-plant-tz="<?= esc((string) qms_setting('regional.timezone', 'Asia/Kolkata'), 'attr') ?>">

    <div class="qms-page-head">
        <div class="min-w-0">
            <h1><?= esc($report['type_name']) ?> <?= status_badge($report['status']) ?></h1>
            <p class="qms-sub"><?= esc($report['part_number']) ?> · <?= esc($report['machine_code']) ?> · <?= esc(plant_date($report['inspection_date'])) ?>
                <?= $report['report_no'] !== null ? ' · ' . esc($report['report_no']) . ((int) $report['revision_no'] > 0 ? ' Rev ' . (int) $report['revision_no'] : '') : ' · Draft #' . (int) $report['id'] ?></p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span data-overall><?= result_badge($report['overall_result']) ?></span>
            <span class="qms-badge qms-badge--fail" data-oos<?= (int) $report['oos_count'] === 0 ? ' hidden' : '' ?>><?= qms_icon('bi-exclamation-octagon-fill') ?> <span data-oos-count><?= (int) $report['oos_count'] ?></span> out of spec</span>
            <a class="btn btn-outline-primary" href="<?= site_url('inspections/' . $report['id']) ?>"><?= qms_icon('bi-eye') ?> View</a>
            <?php if ($actions['cancel']): ?>
                <button class="btn btn-outline-danger" type="button" data-sign-action="cancel" data-sign-url="<?= site_url('inspections/' . $report['id'] . '/cancel') ?>"
                        data-sign-title="Cancel this report" data-sign-meaning="The report will be closed as cancelled. It stays in the system for traceability."
                        data-sign-remarks="required" data-sign-password="no" data-sign-button="Cancel report" data-sign-tone="btn-danger"><?= qms_icon('bi-x-circle') ?> Cancel</button>
            <?php endif ?>
        </div>
    </div>

    <noscript><div class="alert alert-danger">JavaScript is required to enter inspection values.</div></noscript>
    <div class="qms-offline-banner" data-offline-banner hidden role="status"><?= qms_icon('bi-wifi-off') ?> You are offline. Values are kept on this tablet and saved automatically when the network returns. Do not close the browser.</div>
    <div class="alert alert-danger" data-conflict-banner hidden role="alert"></div>

    <?php if ($report['status'] === 'RETURNED' && $returned !== null): ?>
        <div class="alert alert-warning"><?= qms_icon('bi-arrow-return-left') ?> <strong>Returned by <?= esc($returned['full_name'] ?? $returned['username']) ?></strong>
            (<?= esc(plant_dt($returned['signed_at'])) ?>): <?= esc((string) $returned['remarks']) ?></div>
    <?php endif ?>
    <?php if ((int) $report['revision_no'] > 0): ?>
        <div class="alert alert-info"><?= qms_icon('bi-layers') ?> Revision <?= (int) $report['revision_no'] ?> of <?= esc($report['report_no']) ?>. Reason: <?= esc((string) $report['revision_reason']) ?></div>
    <?php endif ?>

    <?= $isGrid ? $this->include('inspections/_entry_grid') : $this->include('inspections/_entry_single') ?>
</div>
<?= view('inspections/_signature_modal', ['report' => $report, 'currentUser' => $currentUser, 'returnTo' => 'edit']) ?>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script type="module" src="<?= qms_asset('assets/js/inspection-form.js') ?>"></script>
<?= $this->endSection() ?>
