<?php

namespace App\Enums;

enum ObservationType: string
{
    case Numeric    = 'NUMERIC';
    case OkNotOk    = 'OK_NOT_OK';
    case GoNoGo     = 'GO_NO_GO';
    case Visual     = 'VISUAL';
    case Text       = 'TEXT';
    case Percentage = 'PERCENTAGE';
    case Date       = 'DATE';
    case Time       = 'TIME';

    public function label(): string
    {
        return match ($this) {
            self::Numeric    => 'Numeric',
            self::OkNotOk    => 'OK / NOT OK',
            self::GoNoGo     => 'GO / NO GO',
            self::Visual     => 'Visual',
            self::Text       => 'Text',
            self::Percentage => 'Percentage',
            self::Date       => 'Date',
            self::Time       => 'Time',
        };
    }

    /** Types that use LSL / USL / nominal / decimals. */
    public function usesLimits(): bool
    {
        return $this === self::Numeric || $this === self::Percentage;
    }

    /**
     * Allowed choice codes => label, or [] for free-entry types.
     *
     * @return array<string, string>
     */
    public function choices(): array
    {
        return match ($this) {
            self::OkNotOk, self::Visual => ['OK' => 'OK', 'NOT_OK' => 'NOT OK'],
            self::GoNoGo                => ['GO' => 'GO', 'NO_GO' => 'NO GO'],
            default                     => [],
        };
    }

    /** @return array<string, string> value => label for selects */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
