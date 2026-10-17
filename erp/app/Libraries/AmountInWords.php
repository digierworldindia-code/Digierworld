<?php

namespace App\Libraries;

/**
 * Indian-system amount in words (lakh / crore), e.g.
 * 11800.00 → "Rupees Eleven Thousand Eight Hundred Only".
 */
final class AmountInWords
{
    private const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    public static function inr(string $amount): string
    {
        $amount = Dec::round(Dec::abs($amount));
        [$rupees, $paise] = explode('.', $amount);
        $rupees = (int) $rupees;
        $paise  = (int) $paise;

        $words = $rupees === 0 ? 'Zero' : self::number($rupees);
        $out   = 'Rupees ' . $words;
        if ($paise > 0) {
            $out .= ' and ' . self::belowHundred($paise) . ' Paise';
        }

        return $out . ' Only';
    }

    private static function number(int $n): string
    {
        $parts = [];
        $crore = intdiv($n, 10000000);
        $n %= 10000000;
        $lakh = intdiv($n, 100000);
        $n %= 100000;
        $thousand = intdiv($n, 1000);
        $n %= 1000;
        $hundred = intdiv($n, 100);
        $rest    = $n % 100;

        if ($crore > 0) {
            $parts[] = self::number($crore) . ' Crore';
        }
        if ($lakh > 0) {
            $parts[] = self::belowHundred($lakh) . ' Lakh';
        }
        if ($thousand > 0) {
            $parts[] = self::belowHundred($thousand) . ' Thousand';
        }
        if ($hundred > 0) {
            $parts[] = self::ONES[$hundred] . ' Hundred';
        }
        if ($rest > 0) {
            $parts[] = self::belowHundred($rest);
        }

        return implode(' ', $parts);
    }

    private static function belowHundred(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }

        return trim(self::TENS[intdiv($n, 10)] . ' ' . self::ONES[$n % 10]);
    }
}
