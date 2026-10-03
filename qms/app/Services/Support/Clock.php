<?php

namespace App\Services\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Single source of "now". Storage is always UTC; the plant time zone is used
 * for display, for "today" and for shift detection. Freezable in tests.
 */
class Clock
{
    private ?DateTimeImmutable $frozenUtc = null;
    private DateTimeZone $plantZone;

    public function __construct(string $plantTimezone = 'Asia/Kolkata')
    {
        try {
            $this->plantZone = new DateTimeZone($plantTimezone !== '' ? $plantTimezone : 'UTC');
        } catch (\Exception) {
            $this->plantZone = new DateTimeZone('UTC');
        }
    }

    /** Freeze time (tests). Pass null to unfreeze. */
    public function freeze(?DateTimeImmutable $instant): void
    {
        $this->frozenUtc = $instant?->setTimezone(new DateTimeZone('UTC'));
    }

    public function nowUtc(): DateTimeImmutable
    {
        return $this->frozenUtc ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** UTC timestamp in MySQL DATETIME format. */
    public function nowUtcString(): string
    {
        return $this->nowUtc()->format('Y-m-d H:i:s');
    }

    public function plantZone(): DateTimeZone
    {
        return $this->plantZone;
    }

    public function plantNow(): DateTimeImmutable
    {
        return $this->nowUtc()->setTimezone($this->plantZone);
    }

    /** Formats a UTC DATETIME string in plant time ('' for null). */
    public function toPlant(?string $utc, string $format = 'd-m-Y H:i'): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }

        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($this->plantZone)->format($format);
        } catch (\Exception) {
            return '';
        }
    }

    /** Converts a plant-local 'Y-m-d H:i[:s]' to a UTC DATETIME string. */
    public function plantToUtc(string $plantLocal): string
    {
        return (new DateTimeImmutable($plantLocal, $this->plantZone))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
