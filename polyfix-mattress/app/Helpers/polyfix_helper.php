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
        $request = service('request');

        // Commands run by an operator have no client address.
        return $request instanceof \CodeIgniter\HTTP\IncomingRequest ? substr((string) $request->getIPAddress(), 0, 64) : '127.0.0.1';
    }
}

if (! function_exists('ip_hash')) {
    /**
     * One-way, keyed hash of the client address — enough to spot abuse from
     * one source, useless for identifying a person. The address itself is
     * never stored with a public submission.
     */
    function ip_hash(): string
    {
        return hash('sha256', client_ip() . ':' . config('Polyfix')->signingSecret);
    }
}

if (! function_exists('invalid')) {
    /** " is-invalid" when the last submission failed validation on this field. */
    function invalid(string $field): string
    {
        return isset((session('errors') ?? [])[$field]) ? ' is-invalid' : '';
    }
}

if (! function_exists('field_error')) {
    /** The escaped validation message for a field, ready to print under it. */
    function field_error(string $field): string
    {
        $errors = session('errors') ?? [];

        return isset($errors[$field])
            ? '<div class="invalid-feedback d-block" id="' . esc($field, 'attr') . '-error">' . esc($errors[$field]) . '</div>'
            : '';
    }
}

if (! function_exists('pill')) {
    /**
     * A status as a coloured pill. Colour carries meaning only: green done/
     * healthy, amber waiting on someone, red a problem, blue in motion.
     */
    function pill(?string $status, ?string $label = null): string
    {
        static $tones = [
            'positive' => ['ACTIVE', 'PUBLISHED', 'RECEIVED', 'APPROVED', 'SOLD', 'OK', 'LOW', 'SUCCESS', 'CONVERTED', 'REPLACED'],
            'caution'  => ['PENDING', 'PENDING_ACTIVATION', 'DRAFT', 'INFO_REQUESTED', 'PARTIALLY_RECEIVED', 'MEDIUM', 'SUBMITTED', 'NEW', 'FOLLOW_UP', 'NOT_ACTIVATED', 'CLAIM_OPEN', 'DAMAGED', 'RUNNING'],
            'critical' => ['SUSPENDED', 'TERMINATED', 'REJECTED', 'VOID', 'HIGH', 'CANCELLED', 'MISSING', 'FAILED', 'DISABLED', 'SCRAPPED'],
            'info'     => ['IN_DISPATCH', 'DISPATCHED', 'UNDER_REVIEW', 'DEALER_RECEIVED', 'CONTACTED', 'MANUFACTURED'],
        ];
        $tone = 'neutral';
        foreach ($tones as $t => $list) {
            if (in_array($status, $list, true)) {
                $tone = $t;
                break;
            }
        }

        return '<span class="pill pill-' . $tone . '">' . esc($label ?? humanise($status)) . '</span>';
    }
}

if (! function_exists('query_url')) {
    /** The current URL with some query parameters replaced (null removes one). */
    function query_url(array $changes): string
    {
        $query = array_filter(array_merge(service('request')->getGet() ?? [], $changes), static fn ($v) => $v !== null && $v !== '');

        return current_url() . ($query === [] ? '' : '?' . http_build_query($query));
    }
}
