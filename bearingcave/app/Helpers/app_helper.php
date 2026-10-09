<?php

declare(strict_types=1);

/**
 * General application helpers (autoloaded).
 */
if (! function_exists('uuid4')) {
    function uuid4(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}

if (! function_exists('policy')) {
    /**
     * Reads a configurable business-policy value (App\Services\SettingsService).
     */
    function policy(string $key, mixed $default = null): mixed
    {
        return service('settings_store')->get($key, $default);
    }
}

if (! function_exists('normalize_part')) {
    /**
     * Normalizes a part number for matching: upper-case, alphanumerics only.
     * "6204-2RS/C3" and "6204 2rs c3" both become "62042RSC3".
     */
    function normalize_part(?string $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value) ?? '');
    }
}

if (! function_exists('current_company')) {
    function current_company(): ?array
    {
        return service('companyContext')->company();
    }
}

if (! function_exists('is_staff')) {
    function is_staff(): bool
    {
        return service('companyContext')->isStaff();
    }
}

if (! function_exists('entitled')) {
    /**
     * Whether the current user's company has a membership feature.
     */
    function entitled(string $feature, ?array $company = null): bool
    {
        $company ??= current_company();

        return $company !== null && service('entitlements')->has($company, $feature);
    }
}

if (! function_exists('make_slug')) {
    function make_slug(string $text, int $max = 180): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $text) ?? '', '-'));

        return mb_substr($slug !== '' ? $slug : 'item', 0, $max);
    }
}
