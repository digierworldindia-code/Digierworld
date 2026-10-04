<?php

namespace App\Enums;

/**
 * Workflow status of an inspection report. PENDING_* names the stage the
 * report is waiting for (see docs/architecture/07-workflow.md).
 */
enum ReportStatus: string
{
    case Draft             = 'DRAFT';
    case PendingProduction = 'PENDING_PRODUCTION';
    case PendingQuality    = 'PENDING_QUALITY';
    case PendingQa         = 'PENDING_QA';
    case QaApproved        = 'QA_APPROVED';
    case Returned          = 'RETURNED';
    case Rejected          = 'REJECTED';
    case Cancelled         = 'CANCELLED';
    case Superseded        = 'SUPERSEDED';

    public function label(): string
    {
        return match ($this) {
            self::Draft             => 'Draft',
            self::PendingProduction => 'Awaiting Production',
            self::PendingQuality    => 'Awaiting Quality',
            self::PendingQa         => 'Awaiting QA',
            self::QaApproved        => 'Approved',
            self::Returned          => 'Returned',
            self::Rejected          => 'Rejected',
            self::Cancelled         => 'Cancelled',
            self::Superseded        => 'Superseded',
        };
    }

    /** Visual tone used by the badge component (colour is never the only cue). */
    public function tone(): string
    {
        return match ($this) {
            self::QaApproved => 'approved',
            self::Rejected   => 'fail',
            self::Returned, self::PendingProduction, self::PendingQuality, self::PendingQa => 'pending',
            default => 'muted',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Draft      => 'bi-pencil-square',
            self::QaApproved => 'bi-patch-check-fill',
            self::Returned   => 'bi-arrow-return-left',
            self::Rejected   => 'bi-slash-circle',
            self::Cancelled  => 'bi-x-circle',
            self::Superseded => 'bi-layers',
            default          => 'bi-hourglass-split',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Returned;
    }

    public function isPending(): bool
    {
        return in_array($this, [self::PendingProduction, self::PendingQuality, self::PendingQa], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::QaApproved, self::Rejected, self::Cancelled, self::Superseded], true);
    }

    /** @return list<string> */
    public static function pendingValues(): array
    {
        return [self::PendingProduction->value, self::PendingQuality->value, self::PendingQa->value];
    }
}
