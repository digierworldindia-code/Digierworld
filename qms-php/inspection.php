<?php
/**
 * Inspection report detail: header, approval progress, results, signatures,
 * workflow actions (verify / approve / return / reject, cancel, revise), print.
 */
require __DIR__ . '/includes/init.php';
$user = require_permission('inspection.view_own', 'inspection.view_all', 'inspection.create',
    'inspection.verify_production', 'inspection.verify_quality', 'inspection.approve_qa');
require_once QMS_ROOT . '/includes/workflow.php';
require_once QMS_ROOT . '/includes/views/inspection/parts.php';

$data = insp_load(get_int('id'), $user);
$report       = $data['report'];
$structure    = $data['structure'];
$rounds       = $data['rounds'];
$gaugeOptions = $data['gaugeOptions'];
$approvals    = $data['approvals'];
$shifts       = $data['shifts'];
$employees    = $data['employees'];
$canEdit      = $data['canEdit'];
$revisions    = $data['revisions'];
$id        = (int) $report['id'];
$title     = insp_title($report);
$actions   = wf_available($report, $user);
$steps     = wf_steps($report, $approvals);
$syncState = sheet_report_state($id);
$stage     = $actions['stage'] !== null ? WORKFLOW_STAGES[$actions['stage']] : null;
$reauth    = setting_bool('workflow.reauth_on_sign', true);
$actionUrl = url('inspection_action.php?id=' . $id);
$lastNote  = null;
foreach (array_reverse($approvals) as $approval) {
    if (in_array($approval['action'], ['RETURNED', 'REJECTED', 'CANCELLED'], true)) {
        $lastNote = $approval;
        break;
    }
}
$timing   = static fn (?string $utc): string => $utc === null ? '–' : plant_dt($utc, 'H:i');
$returnTo = 'show';

$page_title = $title;
require QMS_ROOT . '/includes/layout/header.php';
?>
<div class="qms-page-head">
    <div class="min-w-0">
        <h1><?= e($title) ?> <?= status_badge($report['status']) ?></h1>
        <p class="qms-sub"><?= e($report['type_name']) ?> · <?= e($report['part_number']) ?> · <?= e($report['machine_code']) ?> · <?= e(plant_date($report['inspection_date'])) ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($actions['edit']): ?><a class="btn btn-primary btn-lg" href="<?= url('inspection_edit.php?id=' . $id) ?>"><?= qms_icon('bi-pencil-square') ?> <?= $report['layout'] === 'SHIFT_GRID' ? 'Open sheet' : 'Continue entry' ?></a><?php endif ?>
        <?php if ($actions['print']): ?>
            <a class="btn btn-outline-primary btn-lg" href="<?= url('inspection_print.php?id=' . $id) ?>" target="_blank" rel="noopener"><?= qms_icon('bi-printer') ?> Print / PDF</a>
        <?php endif ?>
    </div>
</div>

<?php if ($lastNote !== null && in_array($report['status'], ['RETURNED', 'REJECTED', 'CANCELLED'], true)): ?>
    <div class="alert <?= $report['status'] === 'RETURNED' ? 'alert-warning' : 'alert-danger' ?>">
        <?= qms_icon(WF_ACTION_LABELS[$lastNote['action']][0]) ?> <strong><?= e(WF_ACTION_LABELS[$lastNote['action']][1]) ?> by <?= e($lastNote['full_name'] ?? $lastNote['username']) ?></strong>
        (<?= e(plant_dt($lastNote['signed_at'])) ?>): <?= e((string) $lastNote['remarks']) ?>
    </div>
<?php endif ?>
<?php if ((int) $report['revision_no'] > 0): ?>
    <div class="alert alert-info"><?= qms_icon('bi-layers') ?> Revision <?= (int) $report['revision_no'] ?> — reason: <?= e((string) $report['revision_reason']) ?></div>
<?php endif ?>
<?php if (count($revisions) > 1): ?>
    <div class="alert alert-light border">
        <?= qms_icon('bi-collection') ?> Versions of <?= e($report['report_no']) ?>:
        <?php foreach ($revisions as $rev): ?>
            <a class="ms-2<?= (int) $rev['id'] === $id ? ' fw-bold' : '' ?>" href="<?= url('inspection.php?id=' . (int) $rev['id']) ?>">Rev <?= (int) $rev['revision_no'] ?></a>
            <span class="small text-muted">(<?= e(status_label($rev['status'])) ?><?= (int) $rev['is_current'] === 1 ? ', current' : '' ?>)</span>
        <?php endforeach ?>
    </div>
<?php endif ?>

<?php require QMS_ROOT . '/includes/views/inspection/flow.php'; ?>

<?php if ($stage !== null && ($actions['sign'] || $actions['signBlocked'] !== null)): ?>
    <div class="card mb-3 qms-sign-card">
        <div class="card-body d-flex flex-wrap align-items-center gap-2">
            <div class="me-auto">
                <strong><?= qms_icon('bi-pen') ?> <?= e($stage['label']) ?></strong>
                <div class="small text-muted"><?= $actions['sign'] ? 'Review the results below, then sign as ' . e($stage['signer']) . '.' : e((string) $actions['signBlocked']) ?></div>
            </div>
            <?php if ($actions['sign']): $pw = $reauth ? 'yes' : 'no'; $isQa = $actions['stage'] === 'QA'; ?>
                <?= insp_sign_button('approve', $actionUrl, ['class' => 'btn btn-success btn-lg', 'title' => $stage['label'],
                    'meaning' => 'You confirm the ' . strtolower($stage['label']) . ' of ' . $title . '.', 'remarks' => 'optional', 'password' => $pw,
                    'button' => $isQa ? 'Approve' : 'Verify', 'tone' => 'btn-success', 'label' => qms_icon('bi-check2-circle') . ' ' . ($isQa ? 'Approve' : 'Verify')]) ?>
                <?= insp_sign_button('return', $actionUrl, ['class' => 'btn btn-outline-warning btn-lg', 'title' => 'Return for correction',
                    'meaning' => 'The report goes back to the originator, who corrects it and submits again.', 'remarks' => 'required', 'password' => $pw,
                    'button' => 'Return', 'tone' => 'btn-warning', 'label' => qms_icon('bi-arrow-return-left') . ' Return']) ?>
                <?= insp_sign_button('reject', $actionUrl, ['class' => 'btn btn-outline-danger btn-lg', 'title' => 'Reject report',
                    'meaning' => 'Rejection is final. The report cannot be corrected afterwards.', 'remarks' => 'required', 'password' => $pw,
                    'button' => 'Reject', 'tone' => 'btn-danger', 'label' => qms_icon('bi-x-octagon') . ' Reject']) ?>
            <?php endif ?>
        </div>
    </div>
<?php endif ?>

<div class="row g-3">
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header">Report details</div>
            <div class="card-body">
                <dl class="qms-dl">
                    <dt>Report no.</dt><dd><?= $report['report_no'] !== null ? e($report['report_no']) . ((int) $report['revision_no'] > 0 ? ' Rev ' . (int) $report['revision_no'] : '') : 'Allocated at submission' ?></dd>
                    <dt>Result</dt><dd><?= result_badge($report['overall_result']) ?><?= (int) $report['oos_count'] > 0 ? ' <span class="text-danger fw-bold">' . (int) $report['oos_count'] . ' OOS</span>' : '' ?></dd>
                    <dt>Part</dt><dd><?= e($report['part_number']) ?><div class="small text-muted"><?= e($report['part_name']) ?></div></dd>
                    <dt>Machine</dt><dd><?= e($report['machine_code']) ?><div class="small text-muted"><?= e($report['machine_name']) ?></div></dd>
                    <dt>Date</dt><dd><?= e(plant_date($report['inspection_date'])) ?></dd>
                    <?php if ($report['header_shift'] !== 'HIDDEN'): ?><dt>Shift</dt><dd><?= e((string) ($report['shift_code'] ?? '–')) ?></dd><?php endif ?>
                    <?php if ($report['header_operator'] !== 'HIDDEN'): ?><dt>Operator</dt><dd><?= e((string) ($report['operator_name'] ?? '–')) ?><?= $report['operator_code'] !== null ? ' <span class="small text-muted">(' . e($report['operator_code']) . ')</span>' : '' ?></dd><?php endif ?>
                    <?php if ($report['header_setter'] !== 'HIDDEN'): ?><dt>Setter</dt><dd><?= e((string) ($report['setter_name'] ?? '–')) ?></dd><?php endif ?>
                    <?php if ($report['header_timing'] !== 'HIDDEN'): ?><dt>Received / finish</dt><dd><?= e($timing($report['received_at'])) ?> / <?= e($timing($report['finish_at'])) ?></dd><?php endif ?>
                    <dt>Format</dt><dd><?= e($report['format_doc_no']) ?> Rev <?= e($report['format_rev_no']) ?><div class="small text-muted">Template <?= e($report['template_code']) ?> v<?= (int) $report['template_version'] ?></div></dd>
                    <dt>Created</dt><dd><?= e((string) $report['created_by_name']) ?> · <?= e(plant_dt($report['created_at'])) ?></dd>
                    <?php if ($report['submitted_at'] !== null): ?><dt>Submitted</dt><dd><?= e((string) $report['submitted_by_name']) ?> · <?= e(plant_dt($report['submitted_at'])) ?></dd><?php endif ?>
                    <?php if ($report['approved_at'] !== null): ?><dt>Approved</dt><dd><?= e((string) $report['approved_by_name']) ?> · <?= e(plant_dt($report['approved_at'])) ?></dd><?php endif ?>
                    <?php if (($report['remarks'] ?? '') !== ''): ?><dt>Remarks</dt><dd><?= e($report['remarks']) ?></dd><?php endif ?>
                    <?php if ($syncState !== null): ?><dt>Google Sheets</dt><dd><?= sync_badge($syncState) ?></dd><?php endif ?>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-xl-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Signatures and history</span>
                <?php if (can('audit.view')): ?><a class="small" href="<?= e(url('admin/audit.php?module=inspection&record=' . $id)) ?>">Audit trail</a><?php endif ?>
            </div>
            <div class="card-body">
                <?php if ($approvals === []): ?><div class="text-muted">No signatures yet.</div><?php endif ?>
                <ol class="qms-timeline">
                    <?php foreach (array_reverse($approvals) as $a): [$icon, $label] = WF_ACTION_LABELS[$a['action']] ?? ['bi-dot', $a['action']]; ?>
                        <li>
                            <?= qms_icon($icon) ?>
                            <div class="min-w-0">
                                <div><span class="who"><?= e($a['full_name'] ?? $a['username']) ?></span>
                                    <span class="text-muted">(<?= e($a['role_name']) ?><?= $a['employee_code'] ? ', ' . e($a['employee_code']) : '' ?>)</span>
                                    — <?= e($label) ?> · <?= e(WF_STAGE_LABELS[$a['stage']] ?? $a['stage']) ?>
                                    <?php if ((int) $a['reauthenticated'] === 1): ?><span class="qms-badge qms-badge--muted" title="Password re-entered"><?= qms_icon('bi-shield-check') ?> e-signature</span><?php endif ?></div>
                                <div class="when"><?= e(plant_dt($a['signed_at'], 'd-m-Y H:i:s')) ?> · <?= e(status_label($a['from_status'])) ?> → <?= e(status_label($a['to_status'])) ?></div>
                                <?php if (($a['remarks'] ?? '') !== ''): ?><div class="small"><?= e($a['remarks']) ?></div><?php endif ?>
                            </div>
                        </li>
                    <?php endforeach ?>
                </ol>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">Inspection results</div>
    <div class="card-body p-0 p-md-2">
        <?php require QMS_ROOT . '/includes/views/inspection/' . ($report['layout'] === 'SHIFT_GRID' ? 'results_grid.php' : 'results_single.php'); ?>
    </div>
</div>

<?php if ($actions['cancel'] || $actions['revise'] || $actions['reviseBlocked'] !== null): ?>
    <div class="card mt-3">
        <div class="card-body d-flex flex-wrap gap-2 align-items-center">
            <?php if ($actions['revise']): ?>
                <?= insp_sign_button('revise', $actionUrl, ['class' => 'btn btn-outline-primary', 'title' => 'Create a correction revision',
                    'meaning' => 'A new revision with a copy of all values is created. This approved version stays valid until the revision is approved.',
                    'remarks' => 'required', 'password' => 'no', 'button' => 'Create revision', 'label' => qms_icon('bi-layers') . ' Create revision']) ?>
            <?php elseif ($actions['reviseBlocked'] !== null): ?>
                <span class="text-muted small"><?= qms_icon('bi-info-circle') ?> <?= e($actions['reviseBlocked']) ?></span>
            <?php endif ?>
            <?php if ($actions['cancel']): ?>
                <?= insp_sign_button('cancel', $actionUrl, ['class' => 'btn btn-outline-danger', 'title' => 'Cancel this report',
                    'meaning' => 'The report will be closed as cancelled. It stays in the system for traceability.', 'remarks' => 'required', 'password' => 'no',
                    'button' => 'Cancel report', 'tone' => 'btn-danger', 'label' => qms_icon('bi-x-circle') . ' Cancel report']) ?>
            <?php endif ?>
        </div>
    </div>
<?php endif ?>

<?php require QMS_ROOT . '/includes/views/inspection/signature_modal.php'; ?>
<?php require QMS_ROOT . '/includes/layout/footer.php';
