<?php

namespace App\Libraries;

/**
 * RFC 6238 time-based one-time passwords: SHA-1, 6 digits, 30-second step,
 * ±1 step tolerance for clock drift. Identical to the previous platform's
 * implementation, so every existing authenticator enrolment keeps working.
 */
class Totp
{
    private const DIGITS  = 6;
    private const PERIOD  = 30;
    private const WINDOW  = 1;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * The issuer is only the label shown in the authenticator app; it is not
     * part of the code calculation, so renaming it breaks no enrolment.
     */
    public static function otpauthUrl(string $secret, string $account, ?string $issuer = null): string
    {
        $issuer ??= brand('shortName');
        $label  = rawurlencode($issuer . ':' . $account);

        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function code(string $secret, ?int $atSeconds = null): string
    {
        return self::compute($secret, intdiv($atSeconds ?? time(), self::PERIOD));
    }

    public static function verify(string $secret, string $code, ?int $atSeconds = null): bool
    {
        $cleaned = (string) preg_replace('/\D/', '', $code);
        if (strlen($cleaned) !== self::DIGITS) {
            return false;
        }

        $current = intdiv($atSeconds ?? time(), self::PERIOD);
        $match   = false;
        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            // Every window is checked, match or not, so timing reveals nothing.
            $match = hash_equals(self::compute($secret, $current + $offset), $cleaned) || $match;
        }

        return $match;
    }

    /** @return list<string> ten single-use codes like ABCDE-FGH23 */
    public static function recoveryCodes(int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw     = substr(self::base32Encode(random_bytes(10)), 0, 10);
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
        }

        return $codes;
    }

    private static function compute(string $secret, int $counter): string
    {
        $key    = self::base32Decode($secret);
        $digest = hash_hmac('sha1', pack('J', $counter), $key, true);
        $offset = ord($digest[19]) & 0x0F;
        $binary = ((ord($digest[$offset]) & 0x7F) << 24)
            | ((ord($digest[$offset + 1]) & 0xFF) << 16)
            | ((ord($digest[$offset + 2]) & 0xFF) << 8)
            | (ord($digest[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $raw): string
    {
        $bits = '';
        foreach (str_split($raw) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $encoded): string
    {
        $clean = strtoupper((string) preg_replace('/[\s=]/', '', $encoded));
        $bits  = '';
        foreach (str_split($clean) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                continue;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
