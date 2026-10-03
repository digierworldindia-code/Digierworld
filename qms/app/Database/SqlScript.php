<?php

namespace App\Database;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/**
 * Executes a reviewed .sql file (reference data, demo data) statement by statement.
 *
 * Only for trusted files shipped with the application. User input never reaches
 * this class. Handles quoted strings, -- and /* *\/ comments; files with
 * DELIMITER blocks (triggers) are not supported, as triggers live in migrations.
 */
final class SqlScript
{
    /**
     * @return int number of statements executed
     */
    public static function run(BaseConnection $db, string $file): int
    {
        if (! is_file($file)) {
            throw new RuntimeException("SQL file not found: {$file}");
        }

        $count = 0;

        foreach (self::split((string) file_get_contents($file)) as $statement) {
            $db->query($statement);
            $count++;
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $current    = '';
        $quote      = null;
        $length     = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $quote !== '`') {
                    $current .= $next;
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '-' && $next === '-') {
                $end = strpos($sql, "\n", $i);
                $i   = $end === false ? $length : $end;

                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i   = $end === false ? $length : $end + 1;

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
            }

            if ($char === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return $statements;
    }
}
