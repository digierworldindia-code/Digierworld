<?php
/**
 * Exact comparison of decimal strings (up to 12 integer and 6 decimal digits,
 * like the DECIMAL(18,6) columns) without floating point: values are scaled to
 * integer millionths, which fit in a 64-bit PHP integer.
 *
 *   dec_compare('6.450', '6.45')  →  0
 *   dec_normalise('6.5')          →  '6.500000'
 */
defined('QMS') || exit;

const DECIMAL_PATTERN = '/^[+-]?\d{1,12}(\.\d{1,6})?$/';

function dec_is(string $value): bool
{
    return (bool) preg_match(DECIMAL_PATTERN, $value);
}

/** Number of digits after the decimal point. */
function dec_places(string $value): int
{
    $dot = strpos($value, '.');

    return $dot === false ? 0 : strlen($value) - $dot - 1;
}

/** <0, 0 or >0 like the <=> operator. */
function dec_compare(string $a, string $b): int
{
    return dec_scaled($a) <=> dec_scaled($b);
}

/** The value in millionths as an integer. */
function dec_scaled(string $value): int
{
    $value = trim($value);
    if (! dec_is($value)) {
        throw new InvalidArgumentException("Not a decimal value: {$value}");
    }
    $negative     = $value[0] === '-';
    $value        = ltrim($value, '+-');
    [$int, $frac] = array_pad(explode('.', $value, 2), 2, '');
    $scaled       = (int) $int * 1_000_000 + (int) str_pad($frac, 6, '0');

    return $negative ? -$scaled : $scaled;
}

/** Canonical DECIMAL(18,6) text, e.g. "6.5" → "6.500000". */
function dec_normalise(string $value): string
{
    $scaled   = dec_scaled($value);
    $negative = $scaled < 0;
    $scaled   = abs($scaled);

    return ($negative ? '-' : '') . intdiv($scaled, 1_000_000) . '.' . str_pad((string) ($scaled % 1_000_000), 6, '0', STR_PAD_LEFT);
}
