<?php

use CodeIgniter\I18n\Time;

/**
 * Small, dependency-free helpers used across services and views.
 */

if (! function_exists('uuid4')) {
    /** Random (version 4) UUID, lowercase, the format every existing id uses. */
    function uuid4(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex      = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}

if (! function_exists('is_uuid')) {
    function is_uuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1;
    }
}

if (! function_exists('utc_now')) {
    /** '2026-09-25 13:04:05.123456' — the stored format, always UTC. */
    function utc_now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}

if (! function_exists('utc_today')) {
    function utc_today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
    }
}

if (! function_exists('local_time')) {
    /**
     * Shows a stored UTC timestamp in India time. Storage stays UTC; only the
     * presentation changes.
     */
    function local_time(?string $utc, string $format = 'd M Y, h:i a'): string
    {
        if ($utc === null || $utc === '') {
            return '—';
        }
        $moment = new DateTimeImmutable($utc, new DateTimeZone('UTC'));

        return $moment->setTimezone(new DateTimeZone('Asia/Kolkata'))->format($format);
    }
}

if (! function_exists('local_date')) {
    function local_date(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        // A plain DATE column has no time zone; a DATETIME is UTC.
        return strlen($value) <= 10
            ? (new DateTimeImmutable($value))->format('d M Y')
            : local_time($value, 'd M Y');
    }
}

if (! function_exists('inr')) {
    /** ₹12,34,567 — Indian digit grouping. */
    function inr(string|int|float|null $amount, bool $paise = false): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }
        $value    = (float) $amount;
        $negative = $value < 0;
        $value    = abs($value);
        $whole    = (string) (int) floor($value);
        $last3    = substr($whole, -3);
        $rest     = substr($whole, 0, -3);
        if ($rest !== '') {
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',';
        }
        $out = '₹' . $rest . $last3;
        if ($paise) {
            $out .= '.' . str_pad((string) round(($value - floor($value)) * 100), 2, '0', STR_PAD_LEFT);
        }

        return ($negative ? '-' : '') . $out;
    }
}

if (! function_exists('humanise')) {
    /** DEALER_RECEIVED -> "Dealer received" */
    function humanise(?string $code): string
    {
        return $code === null ? '—' : ucfirst(strtolower(str_replace('_', ' ', $code)));
    }
}

if (! function_exists('client_ip')) {
    function client_ip(): string
    {
        return substr((string) service('request')->getIPAddress(), 0, 64);
    }
}
