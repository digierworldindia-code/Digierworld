<?php

namespace App\Enums;

/**
 * Verification stages after the operator's submission, in workflow order.
 */
enum WorkflowStage: string
{
    case Production = 'PRODUCTION';
    case Quality    = 'QUALITY';
    case Qa         = 'QA';

    public function label(): string
    {
        return match ($this) {
            self::Production => 'Production Verification',
            self::Quality    => 'Quality Verification',
            self::Qa         => 'QA Approval',
        };
    }

    /** Signer title printed on reports. */
    public function signerTitle(): string
    {
        return match ($this) {
            self::Production => 'Production Engineer',
            self::Quality    => 'Quality Engineer',
            self::Qa         => 'QA Approval',
        };
    }

    public function permission(): string
    {
        return match ($this) {
            self::Production => 'inspection.verify_production',
            self::Quality    => 'inspection.verify_quality',
            self::Qa         => 'inspection.approve_qa',
        };
    }

    public function pendingStatus(): ReportStatus
    {
        return match ($this) {
            self::Production => ReportStatus::PendingProduction,
            self::Quality    => ReportStatus::PendingQuality,
            self::Qa         => ReportStatus::PendingQa,
        };
    }

    /** Value stored in inspection_approvals.stage. */
    public function approvalStage(): string
    {
        return match ($this) {
            self::Production => 'PRODUCTION_VERIFICATION',
            self::Quality    => 'QUALITY_VERIFICATION',
            self::Qa         => 'QA_APPROVAL',
        };
    }

    /** report_types column that switches this stage on or off. */
    public function configColumn(): string
    {
        return match ($this) {
            self::Production => 'requires_production_verification',
            self::Quality    => 'requires_quality_verification',
            self::Qa         => 'requires_qa_approval',
        };
    }

    public static function forPendingStatus(ReportStatus $status): ?self
    {
        foreach (self::cases() as $stage) {
            if ($stage->pendingStatus() === $status) {
                return $stage;
            }
        }

        return null;
    }
}
