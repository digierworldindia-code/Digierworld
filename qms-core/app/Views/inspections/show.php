<?php
/**
 * Inspection report detail: header, approval progress, results, signatures,
 * workflow actions (verify / approve / return / reject, cancel, revise), print.
 *
 * @var array<string, mixed>       $report
 * @var array<string, mixed>       $actions
 * @var list<array<string, mixed>> $steps
 * @var list<array<string, mixed>> $approvals
 * @var list<array<string, mixed>> $revisions
 * @var string|null                $syncState
 */
use App\Enums\ReportStatus;

$id       = (int) $report['id'];
$status   = ReportStatus::from($report['status']);
$stage    = $actions['stage'];
$reauth   = (bool) qms_setting('workflow.reauth_on_sign', true);
$lastNote = null;
foreach (array_reverse($approvals) as $a) {
    if (in_array($a['action'], ['RETURNED', 'REJECTED', 'CANCELLED'], true)) {
        $lastNote = $a;
        break;
    }
}
$actionLabels = [
    'SUBMITTED' => ['bi-send-check', 'Submitted'], 'APPROVED' => ['bi-check-circle-fill', 'Signed'], 'RETURNED' => ['bi-arrow-return-left', 'Returned'],
    'REJECTED' => ['bi-x-octagon-fill', 'Rejected'], 'CANCELLED' => ['bi-x-circle', 'Cancelled'], 'REVISION_CREATED' => ['bi-layers', 'Revision created'],
    'SUPERSEDED' => ['bi-layers-half', 'Superseded'],
];
$stageLabels = [
    'SUBMISSION' => 'Submission', 'PRODUCTION_VERIFICATION' => 'Production verification', 'QUALITY_VERIFICATION' => 'Quality verification',
    'QA_APPROVAL' => 'QA approval', 'REVISION' => 'Revision', 'CANCELLATION' => 'Cancellation',
];
$timing = static fn (?string $utc): string => $utc === null ? '–' : plant_dt($utc, 'H:i');
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="qms-page-head">
    <div class="min-w-0">
        <h1><?= esc($title) ?> <?= status_badge($report['status']) ?></h1>
        <p class="qms-sub"><?= esc($report['type_name']) ?> · <?= esc($report['part_number']) ?> · <?= esc($report['machine_code']) ?> · <?= esc(plant_date($report['inspection_date'])) ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($actions['edit']): ?><a class="btn btn-primary btn-lg" href="<?= site_url('inspections/' . $id . '/edit') ?>"><?= qms_icon('bi-pencil-square') ?> <?= $report['layout'] === 'SHIFT_GRID' ? 'Open sheet' : 'Continue entry' ?></a><?php endif ?>
        <?php if ($actions['print']): ?>
            <a class="btn btn-outline-primary btn-lg" href="<?= site_url('inspections/' . $id . '/print') ?>" target="_blank" rel="noopener"><?= qms_icon('bi-printer') ?> Print</a>
            <a class="btn btn-outline-primary btn-lg" href="<?= site_url('inspections/' . $id . '/pdf') ?>" target="_blank" rel="noopener"><?= qms_icon('bi-file-earmark-pdf') ?> PDF</a>
        <?php endif ?>
    </div>
</div>

<?php if ($lastNote !== null && in_array($report['status'], ['RETURNED', 'REJECTED', 'CANCELLED'], true)): ?>
    <div class="alert <?= $report['status'] === 'RETURNED' ? 'alert-warning' : 'alert-danger' ?>">
        <?= qms_icon($actionLabels[$lastNote['action']][0]) ?> <strong><?= esc($actionLabels[$lastNote['action']][1]) ?> by <?= esc($lastNote['full_name'] ?? $lastNote['username']) ?></strong>
        (<?= esc(plant_dt($lastNote['signed_at'])) ?>): <?= esc((string) $lastNote['remarks']) ?>
    </div>
<?php endif ?>
<?php if ((int) $report['revision_no'] > 0): ?>
    <div class="alert alert-info"><?= qms_icon('bi-layers') ?> Revision <?= (int) $report['revision_no'] ?> — reason: <?= esc((string) $report['revision_reason']) ?></div>
<?php endif ?>
<?php if (count($revisions) > 1): ?>
    <div class="alert alert-light border">
        <?= qms_icon('bi-collection') ?> Versions of <?= esc($report['report_no']) ?>:
        <?php foreach ($revisions as $rev): ?>
            <a class="ms-2<?= (int) $rev['id'] === $id ? ' fw-bold' : '' ?>" href="<?= site_url('inspections/' . $rev['id']) ?>">Rev <?= (int) $rev['revision_no'] ?></a>
            <span class="small text-muted">(<?= esc(ReportStatus::from($rev['status'])->label()) ?><?= (int) $rev['is_current'] === 1 ? ', current' : '' ?>)</span>
        <?php endforeach ?>
    </div>
<?php endif ?>

<?= $this->include('inspections/_flow') ?>

<?php if ($stage !== null && ($actions['sign'] || $actions['signBlocked'] !== null)): ?>
    <div class="card mb-3 qms-sign-card">
        <div class="card-body d-flex flex-wrap align-items-center gap-2">
            <div class="me-auto">
                <strong><?= qms_icon('bi-pen') ?> <?= esc($stage->label()) ?></strong>
                <div class="small text-muted"><?= $actions['sign'] ? 'Review the results below, then sign as ' . esc($stage->signerTitle()) . '.' : esc((string) $actions['signBlocked']) ?></div>
            </div>
            <?php if ($actions['sign']): ?>
                <?php $url = site_url('inspections/' . $id . '/action'); $pw = $reauth ? 'yes' : 'no'; ?>
                <button class="btn btn-success btn-lg" type="button" data-sign-action="approve" data-sign-url="<?= $url ?>" data-sign-title="<?= esc($stage->label(), 'attr') ?>"
                        data-sign-meaning="<?= esc('You confirm the ' . strtolower($stage->label()) . ' of ' . $title . '.', 'attr') ?>" data-sign-remarks="optional" data-sign-password="<?= $pw ?>"
                        data-sign-button="<?= $stage->value === 'QA' ? 'Approve' : 'Verify' ?>" data-sign-tone="btn-success"><?= qms_icon('bi-check2-circle') ?> <?= $stage->value === 'QA' ? 'Approve' : 'Verify' ?></button>
                <button class="btn btn-outline-warning btn-lg" type="button" data-sign-action="return" data-sign-url="<?= $url ?>" data-sign-title="Return for correction"
                        data-sign-meaning="The report goes back to the originator, who corrects it and submits again." data-sign-remarks="required" data-sign-password="<?= $pw ?>"
                        data-sign-button="Return" data-sign-tone="btn-warning"><?= qms_icon('bi-arrow-return-left') ?> Return</button>
                <button class="btn btn-outline-danger btn-lg" type="button" data-sign-action="reject" data-sign-url="<?= $url ?>" data-sign-title="Reject report"
                        data-sign-meaning="Rejection is final. The report cannot be corrected afterwards." data-sign-remarks="required" data-sign-password="<?= $pw ?>"
                        data-sign-button="Reject" data-sign-tone="btn-danger"><?= qms_icon('bi-x-octagon') ?> Reject</button>
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
                    <dt>Report no.</dt><dd><?= $report['report_no'] !== null ? esc($report['report_no']) . ((int) $report['revision_no'] > 0 ? ' Rev ' . (int) $report['revision_no'] : '') : 'Allocated at submission' ?></dd>
                    <dt>Result</dt><dd><?= result_badge($report['overall_result']) ?><?= (int) $report['oos_count'] > 0 ? ' <span class="text-danger fw-bold">' . (int) $report['oos_count'] . ' OOS</span>' : '' ?></dd>
                    <dt>Part</dt><dd><?= esc($report['part_number']) ?><div class="small text-muted"><?= esc($report['part_name']) ?></div></dd>
                    <dt>Machine</dt><dd><?= esc($report['machine_code']) ?><div class="small text-muted"><?= esc($report['machine_name']) ?></div></dd>
                    <dt>Date</dt><dd><?= esc(plant_date($report['inspection_date'])) ?></dd>
                    <?php if ($report['header_shift'] !== 'HIDDEN'): ?><dt>Shift</dt><dd><?= esc((string) ($report['shift_code'] ?? '–')) ?></dd><?php endif ?>
                    <?php if ($report['header_operator'] !== 'HIDDEN'): ?><dt>Operator</dt><dd><?= esc((string) ($report['operator_name'] ?? '–')) ?><?= $report['operator_code'] !== null ? ' <span class="small text-muted">(' . esc($report['operator_code']) . ')</span>' : '' ?></dd><?php endif ?>
                    <?php if ($report['header_setter'] !== 'HIDDEN'): ?><dt>Setter</dt><dd><?= esc((string) ($report['setter_name'] ?? '–')) ?></dd><?php endif ?>
                    <?php if ($report['header_timing'] !== 'HIDDEN'): ?><dt>Received / finish</dt><dd><?= esc($timing($report['received_at'])) ?> / <?= esc($timing($report['finish_at'])) ?></dd><?php endif ?>
                    <dt>Format</dt><dd><?= esc($report['format_doc_no']) ?> Rev <?= esc($report['format_rev_no']) ?><div class="small text-muted">Template <?= esc($report['template_code']) ?> v<?= (int) $report['template_version'] ?></div></dd>
                    <dt>Created</dt><dd><?= esc((string) $report['created_by_name']) ?> · <?= esc(plant_dt($report['created_at'])) ?></dd>
                    <?php if ($report['submitted_at'] !== null): ?><dt>Submitted</dt><dd><?= esc((string) $report['submitted_by_name']) ?> · <?= esc(plant_dt($report['submitted_at'])) ?></dd><?php endif ?>
                    <?php if ($report['approved_at'] !== null): ?><dt>Approved</dt><dd><?= esc((string) $report['approved_by_name']) ?> · <?= esc(plant_dt($report['approved_at'])) ?></dd><?php endif ?>
                    <?php if (($report['remarks'] ?? '') !== ''): ?><dt>Remarks</dt><dd><?= esc($report['remarks']) ?></dd><?php endif ?>
                    <?php if ($syncState !== null): ?><dt>Google Sheets</dt><dd><?= sync_badge($syncState) ?></dd><?php endif ?>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-xl-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Signatures and history</span>
                <?php if (can('audit.view')): ?><a class="small" href="<?= site_url('admin/audit?module=inspection&record=' . $id) ?>">Audit trail</a><?php endif ?>
            </div>
            <div class="card-body">
                <?php if ($approvals === []): ?><div class="text-muted">No signatures yet.</div><?php endif ?>
                <ol class="qms-timeline">
                    <?php foreach (array_reverse($approvals) as $a): [$icon, $label] = $actionLabels[$a['action']] ?? ['bi-dot', $a['action']]; ?>
                        <li>
                            <?= qms_icon($icon) ?>
                            <div class="min-w-0">
                                <div><span class="who"><?= esc($a['full_name'] ?? $a['username']) ?></span>
                                    <span class="text-muted">(<?= esc($a['role_name']) ?><?= $a['employee_code'] ? ', ' . esc($a['employee_code']) : '' ?>)</span>
                                    — <?= esc($label) ?> · <?= esc($stageLabels[$a['stage']] ?? $a['stage']) ?>
                                    <?php if ((int) $a['reauthenticated'] === 1): ?><span class="qms-badge qms-badge--muted" title="Password re-entered"><?= qms_icon('bi-shield-check') ?> e-signature</span><?php endif ?></div>
                                <div class="when"><?= esc(plant_dt($a['signed_at'], 'd-m-Y H:i:s')) ?> · <?= esc(ReportStatus::from($a['from_status'])->label()) ?> → <?= esc(ReportStatus::from($a['to_status'])->label()) ?></div>
                                <?php if (($a['remarks'] ?? '') !== ''): ?><div class="small"><?= esc($a['remarks']) ?></div><?php endif ?>
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
        <?= $report['layout'] === 'SHIFT_GRID' ? $this->include('inspections/_results_grid') : $this->include('inspections/_results_single') ?>
    </div>
</div>

<?php if ($actions['cancel'] || $actions['revise'] || $actions['reviseBlocked'] !== null): ?>
    <div class="card mt-3">
        <div class="card-body d-flex flex-wrap gap-2 align-items-center">
            <?php if ($actions['revise']): ?>
                <button class="btn btn-outline-primary" type="button" data-sign-action="revise" data-sign-url="<?= site_url('inspections/' . $id . '/revise') ?>"
                        data-sign-title="Create a correction revision" data-sign-meaning="A new revision with a copy of all values is created. This approved version stays valid until the revision is approved."
                        data-sign-remarks="required" data-sign-password="no" data-sign-button="Create revision"><?= qms_icon('bi-layers') ?> Create revision</button>
            <?php elseif ($actions['reviseBlocked'] !== null): ?>
                <span class="text-muted small"><?= qms_icon('bi-info-circle') ?> <?= esc($actions['reviseBlocked']) ?></span>
            <?php endif ?>
            <?php if ($actions['cancel']): ?>
                <button class="btn btn-outline-danger" type="button" data-sign-action="cancel" data-sign-url="<?= site_url('inspections/' . $id . '/cancel') ?>"
                        data-sign-title="Cancel this report" data-sign-meaning="The report will be closed as cancelled. It stays in the system for traceability."
                        data-sign-remarks="required" data-sign-password="no" data-sign-button="Cancel report" data-sign-tone="btn-danger"><?= qms_icon('bi-x-circle') ?> Cancel report</button>
            <?php endif ?>
        </div>
    </div>
<?php endif ?>

<?= view('inspections/_signature_modal', ['report' => $report, 'currentUser' => $currentUser, 'returnTo' => 'show']) ?>
<?= $this->endSection() ?>
