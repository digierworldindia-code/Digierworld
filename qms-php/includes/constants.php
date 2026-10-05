<?php
/**
 * Fixed lists: report statuses, approval stages, observation types.
 */
defined('QMS') || exit;

/** Workflow status of a report: label, badge tone and icon. */
const REPORT_STATUSES = [
    'DRAFT'              => ['label' => 'Draft', 'tone' => 'muted', 'icon' => 'bi-pencil-square'],
    'PENDING_PRODUCTION' => ['label' => 'Awaiting Production', 'tone' => 'pending', 'icon' => 'bi-hourglass-split'],
    'PENDING_QUALITY'    => ['label' => 'Awaiting Quality', 'tone' => 'pending', 'icon' => 'bi-hourglass-split'],
    'PENDING_QA'         => ['label' => 'Awaiting QA', 'tone' => 'pending', 'icon' => 'bi-hourglass-split'],
    'QA_APPROVED'        => ['label' => 'Approved', 'tone' => 'approved', 'icon' => 'bi-patch-check-fill'],
    'RETURNED'           => ['label' => 'Returned', 'tone' => 'pending', 'icon' => 'bi-arrow-return-left'],
    'REJECTED'           => ['label' => 'Rejected', 'tone' => 'fail', 'icon' => 'bi-slash-circle'],
    'CANCELLED'          => ['label' => 'Cancelled', 'tone' => 'muted', 'icon' => 'bi-x-circle'],
    'SUPERSEDED'         => ['label' => 'Superseded', 'tone' => 'muted', 'icon' => 'bi-layers'],
];

const PENDING_STATUSES = ['PENDING_PRODUCTION', 'PENDING_QUALITY', 'PENDING_QA'];
const FINAL_STATUSES   = ['QA_APPROVED', 'REJECTED', 'CANCELLED', 'SUPERSEDED'];

/** Approval stages after the operator's submission, in workflow order. */
const WORKFLOW_STAGES = [
    'PRODUCTION' => [
        'label' => 'Production Verification', 'signer' => 'Production Engineer', 'permission' => 'inspection.verify_production',
        'pending' => 'PENDING_PRODUCTION', 'approval_stage' => 'PRODUCTION_VERIFICATION', 'column' => 'requires_production_verification',
    ],
    'QUALITY' => [
        'label' => 'Quality Verification', 'signer' => 'Quality Engineer', 'permission' => 'inspection.verify_quality',
        'pending' => 'PENDING_QUALITY', 'approval_stage' => 'QUALITY_VERIFICATION', 'column' => 'requires_quality_verification',
    ],
    'QA' => [
        'label' => 'QA Approval', 'signer' => 'QA Approval', 'permission' => 'inspection.approve_qa',
        'pending' => 'PENDING_QA', 'approval_stage' => 'QA_APPROVAL', 'column' => 'requires_qa_approval',
    ],
];

/** Observation types: label, and whether LSL / USL / decimals apply. */
const OBSERVATION_TYPES = [
    'NUMERIC'    => 'Numeric',
    'OK_NOT_OK'  => 'OK / NOT OK',
    'GO_NO_GO'   => 'GO / NO GO',
    'VISUAL'     => 'Visual',
    'TEXT'       => 'Text',
    'PERCENTAGE' => 'Percentage',
    'DATE'       => 'Date',
    'TIME'       => 'Time',
];

function status_label(string $status): string
{
    return REPORT_STATUSES[$status]['label'] ?? $status;
}

function status_editable(string $status): bool
{
    return $status === 'DRAFT' || $status === 'RETURNED';
}

/** Stage key (PRODUCTION / QUALITY / QA) the report is waiting for, or null. */
function stage_for_status(string $status): ?string
{
    foreach (WORKFLOW_STAGES as $key => $stage) {
        if ($stage['pending'] === $status) {
            return $key;
        }
    }

    return null;
}

function obs_uses_limits(string $type): bool
{
    return $type === 'NUMERIC' || $type === 'PERCENTAGE';
}

/** Allowed choice codes => label for OK / GO types, [] for free entry. */
function obs_choices(string $type): array
{
    return match ($type) {
        'OK_NOT_OK', 'VISUAL' => ['OK' => 'OK', 'NOT_OK' => 'NOT OK'],
        'GO_NO_GO'            => ['GO' => 'GO', 'NO_GO' => 'NO GO'],
        default               => [],
    };
}
