<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Executes a reviewed .sql file shipped with the application (schema,
 * reference data, demo data) statement by statement.
 *
 * Understands quoted strings, -- / # / C-style comments and DELIMITER lines
 * (the integrity triggers in qms_schema.sql use DELIMITER $$). Only trusted
 * files from the database/ folder are ever run; user input never reaches it.
 */
final class SqlScript
{
    /**
     * @return int number of statements executed
     */
    public static function run(Database $db, string $file): int
    {
        if (! is_file($file)) {
            throw new RuntimeException("SQL file not found: {$file}");
        }

        $count = 0;
        foreach (self::split((string) file_get_contents($file)) as $statement) {
            $db->exec($statement);
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
        $delimiter  = ';';
        $quote      = null;
        $length     = strlen($sql);
        $lineStart  = true;

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
                $lineStart = false;

                continue;
            }

            // DELIMITER <token> on its own line (client directive, not SQL).
            if ($lineStart && strncasecmp(substr($sql, $i, 10), 'DELIMITER ', 10) === 0) {
                $end       = strpos($sql, "\n", $i);
                $line      = $end === false ? substr($sql, $i) : substr($sql, $i, $end - $i);
                $delimiter = trim(substr($line, 10));
                if ($delimiter === '') {
                    throw new RuntimeException('Empty DELIMITER in SQL script.');
                }
                $i = $end === false ? $length : $end;

                continue;
            }

            if (($char === '-' && $next === '-' && in_array($sql[$i + 2] ?? ' ', [' ', "\t", "\n", "\r"], true)) || $char === '#') {
                $end = strpos($sql, "\n", $i);
                $i   = $end === false ? $length : $end - 1;

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

            if (substr($sql, $i, strlen($delimiter)) === $delimiter) {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }
                $current   = '';
                $i        += strlen($delimiter) - 1;
                $lineStart = false;

                continue;
            }

            $current  .= $char;
            $lineStart = $char === "\n" || ($lineStart && ($char === ' ' || $char === "\t" || $char === "\r"));
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return $statements;
    }
}
