<?php

namespace App\Libraries;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Exact decimal arithmetic for money, tax and quantities.
 *
 * All inputs/outputs are numeric strings so values round-trip with MySQL
 * DECIMAL columns without ever touching binary floating point.
 * Rounding policy: HALF_UP ("commercial rounding"), documented in
 * docs/01-BLUEPRINT.md §5 and applied per tax component per line.
 */
final class Dec
{
    public static function of(int|float|string|null $v): BigDecimal
    {
        if ($v === null || $v === '') {
            return BigDecimal::zero();
        }
        if (is_float($v)) {
            // floats only ever come from loose user input; normalise via string
            $v = rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
        }
        $v = str_replace([',', ' ', '₹'], '', (string) $v);

        return BigDecimal::of($v === '' || $v === '-' ? '0' : $v);
    }

    public static function add(int|float|string|null ...$vals): string
    {
        $sum = BigDecimal::zero();
        foreach ($vals as $v) {
            $sum = $sum->plus(self::of($v));
        }

        return (string) $sum->toScale(2, RoundingMode::HalfUp);
    }

    public static function sub(int|float|string|null $a, int|float|string|null $b): string
    {
        return (string) self::of($a)->minus(self::of($b))->toScale(2, RoundingMode::HalfUp);
    }

    /** a × b rounded to $scale. */
    public static function mul(int|float|string|null $a, int|float|string|null $b, int $scale = 2): string
    {
        return (string) self::of($a)->multipliedBy(self::of($b))->toScale($scale, RoundingMode::HalfUp);
    }

    /** amount × rate% rounded to 2dp — e.g. pct('10000', '9') = '900.00'. */
    public static function pct(int|float|string|null $amount, int|float|string|null $ratePercent): string
    {
        return (string) self::of($amount)->multipliedBy(self::of($ratePercent))
            ->dividedBy('100', 2, RoundingMode::HalfUp);
    }

    public static function div(int|float|string|null $a, int|float|string|null $b, int $scale = 2): string
    {
        if (self::isZero($b)) {
            return (string) BigDecimal::zero()->toScale($scale);
        }

        return (string) self::of($a)->dividedBy(self::of($b), $scale, RoundingMode::HalfUp);
    }

    public static function round(int|float|string|null $v, int $scale = 2): string
    {
        return (string) self::of($v)->toScale($scale, RoundingMode::HalfUp);
    }

    public static function cmp(int|float|string|null $a, int|float|string|null $b): int
    {
        return self::of($a)->compareTo(self::of($b));
    }

    public static function isZero(int|float|string|null $v): bool
    {
        return self::of($v)->isZero();
    }

    public static function isPositive(int|float|string|null $v): bool
    {
        return self::of($v)->isPositive();
    }

    public static function isNegative(int|float|string|null $v): bool
    {
        return self::of($v)->isNegative();
    }

    public static function neg(int|float|string|null $v): string
    {
        return (string) self::of($v)->negated()->toScale(2, RoundingMode::HalfUp);
    }

    public static function abs(int|float|string|null $v): string
    {
        return (string) self::of($v)->abs()->toScale(2, RoundingMode::HalfUp);
    }

    public static function min(int|float|string|null $a, int|float|string|null $b): string
    {
        return self::cmp($a, $b) <= 0 ? self::round($a) : self::round($b);
    }

    public static function max(int|float|string|null $a, int|float|string|null $b): string
    {
        return self::cmp($a, $b) >= 0 ? self::round($a) : self::round($b);
    }

    /** Round to nearest whole rupee (HALF_UP). */
    public static function roundRupee(int|float|string|null $v): string
    {
        return (string) self::of($v)->toScale(0, RoundingMode::HalfUp)->toScale(2);
    }

    /** Normalise a rate such as "9" or "9.000" to the shortest display form ("9", "2.5"). */
    public static function rateLabel(int|float|string|null $rate): string
    {
        return (string) self::of($rate)->strippedOfTrailingZeros();
    }
}
