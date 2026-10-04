<?php

namespace App\Services\Inspections;

use InvalidArgumentException;

/**
 * Exact comparison of decimal strings (up to 12 integer and 6 fractional digits,
 * i.e. MySQL DECIMAL(18,6)) without floating point: values are scaled to integer
 * micro-units, which fit in a 64-bit PHP int.
 */
final class DecimalComparator
{
    public const PATTERN = '/^[+-]?\d{1,12}(\.\d{1,6})?$/';

    public static function isDecimal(string $value): bool
    {
        return (bool) preg_match(self::PATTERN, $value);
    }

    /** Number of digits after the decimal point. */
    public static function decimals(string $value): int
    {
        $dot = strpos($value, '.');

        return $dot === false ? 0 : strlen($value) - $dot - 1;
    }

    /** <0, 0, >0 like the spaceship operator. */
    public static function compare(string $a, string $b): int
    {
        return self::scaled($a) <=> self::scaled($b);
    }

    public static function scaled(string $value): int
    {
        $value = trim($value);
        if (! self::isDecimal($value)) {
            throw new InvalidArgumentException("Not a decimal value: {$value}");
        }
        $negative = $value[0] === '-';
        $value    = ltrim($value, '+-');
        [$int, $frac] = array_pad(explode('.', $value, 2), 2, '');
        $scaled = (int) $int * 1_000_000 + (int) str_pad($frac, 6, '0');

        return $negative ? -$scaled : $scaled;
    }

    /** Canonical form stored in DECIMAL columns, e.g. "6.5" → "6.500000". */
    public static function normalise(string $value): string
    {
        $scaled   = self::scaled($value);
        $negative = $scaled < 0;
        $scaled   = abs($scaled);

        return ($negative ? '-' : '') . intdiv($scaled, 1_000_000) . '.' . str_pad((string) ($scaled % 1_000_000), 6, '0', STR_PAD_LEFT);
    }
}
