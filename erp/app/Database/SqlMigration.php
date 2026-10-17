<?php

namespace App\Database;

use CodeIgniter\Database\Migration;

/**
 * Base migration that executes explicit MySQL 8 DDL.
 *
 * Every table is InnoDB/utf8mb4 with explicit FKs, unique keys and CHECK
 * constraints so the schema can be reviewed directly by a DBA.
 */
abstract class SqlMigration extends Migration
{
    protected const TABLE_OPTS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    /** Standard audit columns appended to most tables. */
    protected const AUDIT_COLS = "
        created_by INT UNSIGNED NULL,
        updated_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP";

    /**
     * @param list<string> $statements
     */
    protected function run(array $statements): void
    {
        foreach ($statements as $sql) {
            $sql = trim($sql);
            if ($sql === '') {
                continue;
            }
            if (str_starts_with($sql, 'CREATE TABLE') && ! str_contains($sql, 'ENGINE=')) {
                $sql .= ' ' . self::TABLE_OPTS;
            }
            $this->db->query($sql);
        }
    }

    /**
     * Drop tables in the given order with FK checks disabled.
     *
     * @param list<string> $tables
     */
    protected function dropTables(array $tables): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
