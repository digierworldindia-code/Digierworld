<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Daily log files in storage/logs (log-YYYY-MM-DD.log). Production keeps
 * error level and above; development keeps everything.
 */
final class Logger
{
    private const LEVELS = ['emergency' => 1, 'alert' => 2, 'critical' => 3, 'error' => 4, 'warning' => 5, 'notice' => 6, 'info' => 7, 'debug' => 8];

    public function __construct(private readonly string $dir, private readonly int $threshold)
    {
    }

    /**
     * @param array<string, mixed> $context values for {placeholders}
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $level = strtolower($level);
        $rank  = self::LEVELS[$level] ?? 4;
        if ($rank > $this->threshold) {
            return;
        }

        $replace = [];
        foreach ($context as $key => $value) {
            $replace['{' . $key . '}'] = match (true) {
                $value instanceof \Throwable => $value::class . ': ' . $value->getMessage() . ' at ' . $value->getFile() . ':' . $value->getLine(),
                is_scalar($value) || $value === null => (string) $value,
                $value instanceof \Stringable => (string) $value,
                default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            };
        }
        // One line per entry; strip control characters (log injection).
        $text = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', ' ', strtr($message, $replace)) ?? '';
        $line = strtoupper($level) . ' - ' . gmdate('Y-m-d H:i:s') . ' UTC --> ' . str_replace("\n", "\n    ", $text) . "\n";

        if (! is_dir($this->dir)) {
            @mkdir($this->dir, 0770, true);
        }
        $file = $this->dir . DIRECTORY_SEPARATOR . 'log-' . gmdate('Y-m-d') . '.log';
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log(rtrim($line));
        }
    }
}
