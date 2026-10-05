<?php
/**
 * Inspection entry screen (tablet first). Autosave, offline queue and the live
 * PASS / FAIL display are handled by assets/js/inspection-form.js; every value
 * is validated and evaluated again on the server (api/autosave.php).
 */
require __DIR__ . '/includes/init.php';
$user = require_permission('inspection.create');
require_once QMS_ROOT . '/includes/workflow.php';
require_once QMS_ROOT . '/includes/views/inspection/parts.php';

$data = insp_load(get_int('id'), $user);
if (! $data['canEdit']) {
    redirect('inspection.php?id=' . get_int('id'));
}
$report       = $data['report'];
$structure    = $data['structure'];
$rounds       = $data['rounds'];
$gaugeOptions = $data['gaugeOptions'];
$approvals    = $data['approvals'];
$shifts       = $data['shifts'];
$employees    = $data['employees'];
$canEdit      = $data['canEdit'];
$revisions    = $data['revisions'];
$actions      = wf_available($report, $user);
$problems     = insp_submission_problems($report);
$autosave     = max(5, setting_int('inspection.autosave_seconds', 20));
$blockExpired = setting_bool('gauge.block_expired_on_submit', true);
$idemKey      = uuid_v4();
$isGrid       = $report['layout'] === 'SHIFT_GRID';
$returned     = null;
foreach (array_reverse($approvals) as $approval) {
    if ($approval['action'] === 'RETURNED') {
        $returned = $approval;
        break;
    }
}
$returnTo = 'edit';

$page_title   = insp_title($report);
$page_scripts = ['inspection-form.js'];
require QMS_ROOT . '/includes/layout/header.php';
?>
<div id="inspectionForm" class="qms-entry<?= $isGrid ? ' is-grid' : '' ?>"
     data-report="<?= (int) $report['id'] ?>" data-lock="<?= (int) $report['lock_version'] ?>" data-status="<?= e($report['status']) ?>"
     data-layout="<?= e($report['layout']) ?>" data-inspection-date="<?= e($report['inspection_date']) ?>"
     data-save-url="<?= e(url('api/autosave.php?id=' . (int) $report['id'])) ?>" data-submit-url="<?= e(url('api/submit.php?id=' . (int) $report['id'])) ?>"
     data-autosave="<?= $autosave ?>" data-user="<?= (int) $user['id'] ?>" data-plant-tz="<?= e(setting_str('regional.timezone', 'Asia/Kolkata')) ?>">

    <div class="qms-page-head">
        <div class="min-w-0">
            <h1><?= e($report['type_name']) ?> <?= status_badge($report['status']) ?></h1>
            <p class="qms-sub"><?= e($report['part_number']) ?> · <?= e($report['machine_code']) ?> · <?= e(plant_date($report['inspection_date'])) ?>
                <?= $report['report_no'] !== null ? ' · ' . e($report['report_no']) . ((int) $report['revision_no'] > 0 ? ' Rev ' . (int) $report['revision_no'] : '') : ' · Draft #' . (int) $report['id'] ?></p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span data-overall><?= result_badge($report['overall_result']) ?></span>
            <span class="qms-badge qms-badge--fail" data-oos<?= (int) $report['oos_count'] === 0 ? ' hidden' : '' ?>><?= qms_icon('bi-exclamation-octagon-fill') ?> <span data-oos-count><?= (int) $report['oos_count'] ?></span> out of spec</span>
            <a class="btn btn-outline-primary" href="<?= url('inspection.php?id=' . (int) $report['id']) ?>"><?= qms_icon('bi-eye') ?> View</a>
            <?php if ($actions['cancel']): ?>
                <?= insp_sign_button('cancel', url('inspection_action.php?id=' . (int) $report['id']), [
                    'class' => 'btn btn-outline-danger', 'title' => 'Cancel this report', 'meaning' => 'The report will be closed as cancelled. It stays in the system for traceability.',
                    'remarks' => 'required', 'password' => 'no', 'button' => 'Cancel report', 'tone' => 'btn-danger', 'label' => qms_icon('bi-x-circle') . ' Cancel',
                ]) ?>
            <?php endif ?>
        </div>
    </div>

    <noscript><div class="alert alert-danger">JavaScript is required to enter inspection values.</div></noscript>
    <div class="qms-offline-banner" data-offline-banner hidden role="status"><?= qms_icon('bi-wifi-off') ?> You are offline. Values are kept on this tablet and saved automatically when the network returns. Do not close the browser.</div>
    <div class="alert alert-danger" data-conflict-banner hidden role="alert"></div>

    <?php if ($report['status'] === 'RETURNED' && $returned !== null): ?>
        <div class="alert alert-warning"><?= qms_icon('bi-arrow-return-left') ?> <strong>Returned by <?= e($returned['full_name'] ?? $returned['username']) ?></strong>
            (<?= e(plant_dt($returned['signed_at'])) ?>): <?= e((string) $returned['remarks']) ?></div>
    <?php endif ?>
    <?php if ((int) $report['revision_no'] > 0): ?>
        <div class="alert alert-info"><?= qms_icon('bi-layers') ?> Revision <?= (int) $report['revision_no'] ?> of <?= e($report['report_no']) ?>. Reason: <?= e((string) $report['revision_reason']) ?></div>
    <?php endif ?>

    <?php require QMS_ROOT . '/includes/views/inspection/' . ($isGrid ? 'entry_grid.php' : 'entry_single.php'); ?>
</div>
<?php require QMS_ROOT . '/includes/views/inspection/signature_modal.php'; ?>
<?php require QMS_ROOT . '/includes/layout/footer.php';
