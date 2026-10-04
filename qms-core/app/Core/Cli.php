<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Small helpers for bin/qms.php: options, coloured output and prompts.
 */
final class Cli
{
    /** @var array<string, string|true> */
    private static array $options = [];

    /** @var list<string> */
    private static array $arguments = [];

    /**
     * @param list<string> $argv without the script name
     */
    public static function parse(array $argv): void
    {
        self::$options   = [];
        self::$arguments = [];
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--')) {
                [$name, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, null);
                self::$options[$name] = $value ?? true;
            } else {
                self::$arguments[] = $arg;
            }
        }
    }

    public static function option(string $name, ?string $default = null): ?string
    {
        $value = self::$options[$name] ?? null;

        return is_string($value) ? $value : $default;
    }

    public static function flag(string $name): bool
    {
        return isset(self::$options[$name]);
    }

    public static function argument(int $index): ?string
    {
        return self::$arguments[$index] ?? null;
    }

    public static function write(string $text = '', ?string $color = null): void
    {
        $codes = ['red' => '31', 'green' => '32', 'yellow' => '33', 'blue' => '34', 'gray' => '90'];
        if ($color !== null && isset($codes[$color]) && self::colors()) {
            $text = "\033[" . $codes[$color] . 'm' . $text . "\033[0m";
        }
        fwrite(STDOUT, $text . PHP_EOL);
    }

    public static function error(string $text): void
    {
        $line = self::colors() ? "\033[31m" . $text . "\033[0m" : $text;
        fwrite(STDERR, $line . PHP_EOL);
    }

    public static function prompt(string $label, string $default = ''): string
    {
        fwrite(STDOUT, $label . ($default !== '' ? " [{$default}]" : '') . ': ');
        $value = trim((string) fgets(STDIN));

        return $value === '' ? $default : $value;
    }

    /** Hidden input (passwords) on a terminal. */
    public static function secret(string $label): string
    {
        fwrite(STDOUT, $label . ': ');
        $tty = DIRECTORY_SEPARATOR === '/' && function_exists('posix_isatty') && @posix_isatty(STDIN) && function_exists('shell_exec');
        if ($tty) {
            shell_exec('stty -echo');
        }
        $value = rtrim((string) fgets(STDIN), "\r\n");
        if ($tty) {
            shell_exec('stty echo');
            fwrite(STDOUT, PHP_EOL);
        }

        return $value;
    }

    public static function confirm(string $question): bool
    {
        return in_array(strtolower(self::prompt($question . ' (y/N)')), ['y', 'yes'], true);
    }

    public static function interactive(): bool
    {
        return function_exists('posix_isatty') ? @posix_isatty(STDIN) : true;
    }

    private static function colors(): bool
    {
        return getenv('NO_COLOR') === false && function_exists('posix_isatty') && @posix_isatty(STDOUT);
    }
}
