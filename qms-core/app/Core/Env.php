<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Reads the .env file (KEY=VALUE lines) that lives in the project root, outside
 * the web root. Real environment variables (for example PHP-FPM env[...]) win
 * over the file, so secrets can also be injected by the server.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $file): void
    {
        self::$values = [];
        if (! is_file($file) || ! is_readable($file)) {
            return;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (str_starts_with($key, 'export ')) {
                $key = trim(substr($key, 7));
            }
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }
            self::$values[$key] = self::unquote($value);
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return self::$values[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value === null || ! is_numeric($value) ? $default : (int) $value;
    }

    /**
     * Test helper: sets a value for the current process only.
     */
    public static function set(string $key, string $value): void
    {
        self::$values[$key] = $value;
    }

    private static function unquote(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $quote = $value[0];
        if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote) && strlen($value) >= 2) {
            $inner = substr($value, 1, -1);

            return $quote === '"' ? stripcslashes($inner) : $inner;
        }
        // Unquoted values may carry a trailing " # comment".
        $hash = strpos($value, ' #');

        return $hash === false ? $value : rtrim(substr($value, 0, $hash));
    }
}
