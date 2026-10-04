<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Synchroniser-token CSRF protection.
 *
 * One random token per session (stored server-side). Pages receive a masked
 * copy that changes on every render (BREACH-safe); forms send it as the field
 * "csrf_qms", fetch() calls as the X-CSRF-TOKEN header. Every POST, PUT, PATCH
 * and DELETE without a valid token is refused.
 */
final class Csrf
{
    private const BYTES = 32;

    public static function tokenName(): string
    {
        return config('Security')->tokenName;
    }

    public static function headerName(): string
    {
        return config('Security')->headerName;
    }

    /** Masked token for forms and the csrf-hash meta tag. */
    public static function hash(): string
    {
        $raw = hex2bin(self::rawToken());
        $key = random_bytes(self::BYTES);

        return bin2hex(($raw ^ $key) . $key);
    }

    public static function field(): string
    {
        return '<input type="hidden" name="' . esc(self::tokenName(), 'attr') . '" value="' . esc(self::hash(), 'attr') . '">';
    }

    public static function verify(Request $request): bool
    {
        $sent = $request->getHeaderLine(self::headerName());
        if ($sent === '') {
            $value = $request->getPost(self::tokenName());
            $sent  = is_string($value) ? $value : '';
        }
        if ($sent === '' && str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
            $json = json_decode($request->getBody(), true);
            $sent = is_array($json) && is_string($json[self::tokenName()] ?? null) ? $json[self::tokenName()] : '';
        }

        $stored = session()->get(self::tokenName());
        if (! is_string($stored) || strlen($stored) !== self::BYTES * 2 || $sent === '') {
            return false;
        }

        return hash_equals($stored, self::unmask($sent));
    }

    private static function rawToken(): string
    {
        $session = session();
        $token   = $session->get(self::tokenName());
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{' . (self::BYTES * 2) . '}$/', $token)) {
            $token = bin2hex(random_bytes(self::BYTES));
            $session->set(self::tokenName(), $token);
        }

        return $token;
    }

    private static function unmask(string $sent): string
    {
        if (! preg_match('/^[a-f0-9]{' . (self::BYTES * 4) . '}$/', $sent)) {
            return '';
        }
        $bytes = (string) hex2bin($sent);

        return bin2hex(substr($bytes, 0, self::BYTES) ^ substr($bytes, self::BYTES));
    }
}
