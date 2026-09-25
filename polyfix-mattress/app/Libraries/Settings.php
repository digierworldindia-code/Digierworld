<?php

namespace App\Libraries;

/**
 * Runtime settings an administrator can change without a deploy (the
 * system_settings table): support phone and email, the footer address, social
 * links, warranty terms version, risk thresholds, feature switches.
 *
 * Values are JSON in the database; read once per request.
 */
final class Settings
{
    private static ?array $cache = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (db_connect()->table('system_settings')->select('key, value')->get()->getResultArray() as $row) {
                self::$cache[$row['key']] = json_decode((string) $row['value'], true);
            }
        }

        $value = self::$cache[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    public static function flag(string $key, bool $default = true): bool
    {
        return (bool) self::get($key, $default);
    }

    /** Called after a setting changes, and between tests. */
    public static function forget(): void
    {
        self::$cache = null;
    }
}
