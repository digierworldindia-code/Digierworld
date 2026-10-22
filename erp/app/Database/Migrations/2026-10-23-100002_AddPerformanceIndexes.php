<?php

namespace App\Database\Migrations;

use App\Database\SqlMigration;

/**
 * Indexes for the date filters used by dashboards and lists across the
 * whole company (the existing indexes start with the user column, so
 * owner-wide counts scanned the full table). Additive only: no data
 * changes; InnoDB builds them while the tables stay usable.
 */
class AddPerformanceIndexes extends SqlMigration
{
    /** index => [table, columns] */
    private const INDEXES = [
        'idx_cl_called'   => ['call_logs', 'called_at'],
        'idx_fu_due'      => ['followups', 'status, due_at'],
        'idx_lcl_conv'    => ['lead_conversion_links', 'converted_at'],
        'idx_al_occurred' => ['audit_logs', 'occurred_at'],
    ];

    private function exists(string $table, string $index): bool
    {
        return $this->db->query('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1', [$table, $index])->getRow() !== null;
    }

    public function up(): void
    {
        foreach (self::INDEXES as $index => [$table, $cols]) {
            if (! $this->exists($table, $index)) {
                $this->run(["ALTER TABLE {$table} ADD INDEX {$index} ({$cols})"]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $index => [$table]) {
            if ($this->exists($table, $index)) {
                $this->run(["ALTER TABLE {$table} DROP INDEX {$index}"]);
            }
        }
    }
}
