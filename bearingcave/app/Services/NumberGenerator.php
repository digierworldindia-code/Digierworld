<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Concurrency-safe sequential document numbers, e.g. RFQ-2026-000042.
 *
 * Uses MySQL's LAST_INSERT_ID(expr) trick so the increment and the read are
 * a single atomic statement per connection.
 */
class NumberGenerator
{
    public function next(string $prefix): string
    {
        $db   = db_connect();
        $year = (int) date('Y');
        // Ensure the counter row exists, then increment it with the documented
        // UPDATE ... LAST_INSERT_ID(expr) pattern (atomic under the row lock).
        $db->query('INSERT IGNORE INTO number_sequences (seq_key, seq_year, counter) VALUES (?, ?, 0)', [$prefix, $year]);
        $db->query('UPDATE number_sequences SET counter = LAST_INSERT_ID(counter + 1) WHERE seq_key = ? AND seq_year = ?', [$prefix, $year]);
        $value = (int) $db->query('SELECT LAST_INSERT_ID() AS v')->getRow()->v;

        return sprintf('%s-%d-%06d', $prefix, $year, $value);
    }
}
