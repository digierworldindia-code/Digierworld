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
        $db->query(
            'INSERT INTO number_sequences (seq_key, seq_year, last_value) VALUES (?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_value = LAST_INSERT_ID(last_value + 1)',
            [$prefix, $year]
        );
        $value = (int) $db->query('SELECT LAST_INSERT_ID() AS v')->getRow()->v;

        return sprintf('%s-%d-%06d', $prefix, $year, $value);
    }
}
