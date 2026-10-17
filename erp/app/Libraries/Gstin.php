<?php

namespace App\Libraries;

/**
 * GSTIN / PAN structural validation.
 *
 * GSTIN = 2-digit state code + 10-char PAN + entity code + 'Z' + checksum.
 * The checksum uses the GSTN mod-36 algorithm. Structural validity does not
 * prove the registration is active — the UI says so.
 */
final class Gstin
{
    private const CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public static function normalize(?string $gstin): ?string
    {
        $g = strtoupper(preg_replace('/\s+/', '', (string) $gstin) ?? '');

        return $g === '' ? null : $g;
    }

    /** @return array{valid: bool, error: ?string, state_code: ?string, pan: ?string} */
    public static function validate(?string $gstin): array
    {
        $g = self::normalize($gstin);
        if ($g === null) {
            return ['valid' => false, 'error' => 'GSTIN is empty.', 'state_code' => null, 'pan' => null];
        }
        if (strlen($g) !== 15) {
            return ['valid' => false, 'error' => 'GSTIN must be exactly 15 characters.', 'state_code' => null, 'pan' => null];
        }
        if (! preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z][A-Z][0-9A-Z]$/', $g)) {
            return ['valid' => false, 'error' => 'GSTIN format is invalid (expected 2-digit state code + PAN + entity code + Z + check digit).', 'state_code' => null, 'pan' => null];
        }
        $state = substr($g, 0, 2);
        if (! in_array($state, self::stateCodes(), true)) {
            return ['valid' => false, 'error' => "GSTIN state code {$state} is not a valid GST state code.", 'state_code' => $state, 'pan' => substr($g, 2, 10)];
        }
        if (self::checkChar(substr($g, 0, 14)) !== $g[14]) {
            return ['valid' => false, 'error' => 'GSTIN check digit does not match — please re-check for typing errors.', 'state_code' => $state, 'pan' => substr($g, 2, 10)];
        }

        return ['valid' => true, 'error' => null, 'state_code' => $state, 'pan' => substr($g, 2, 10)];
    }

    public static function checkChar(string $first14): string
    {
        $factor = 2;
        $sum    = 0;
        $mod    = strlen(self::CHARS);
        for ($i = strlen($first14) - 1; $i >= 0; $i--) {
            $code   = strpos(self::CHARS, $first14[$i]);
            $digit  = $factor * ($code === false ? 0 : $code);
            $factor = $factor === 2 ? 1 : 2;
            $sum   += intdiv($digit, $mod) + ($digit % $mod);
        }

        return self::CHARS[($mod - ($sum % $mod)) % $mod];
    }

    public static function validPan(?string $pan): bool
    {
        return (bool) preg_match('/^[A-Z]{3}[ABCFGHLJPT][A-Z][0-9]{4}[A-Z]$/', strtoupper(trim((string) $pan)));
    }

    /** @return list<string> */
    public static function stateCodes(): array
    {
        return ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '13', '14', '15', '16', '17', '18', '19', '20',
            '21', '22', '23', '24', '25', '26', '27', '29', '30', '31', '32', '33', '34', '35', '36', '37', '38', '97', '99'];
    }
}
