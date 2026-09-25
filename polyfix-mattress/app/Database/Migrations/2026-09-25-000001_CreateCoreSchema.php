<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Creates the 41 business tables and their 58 foreign keys.
 *
 * Idempotent against an existing database: a table that already exists is left
 * exactly as it is — this migration never drops, truncates or recreates one.
 * That is what lets it run safely against a database imported from a dump.
 *
 * No triggers, on purpose. MySQL refuses CREATE TRIGGER from a non-SUPER user
 * while binary logging is on (error 1419), which is the default on managed and
 * shared hosting. Append-only history is enforced by privileges instead; see
 * database/sql/privileges.sql and, for hosts that allow it, the optional
 * database/sql/audit-triggers.sql.
 */
class CreateCoreSchema extends Migration
{
    public function up(): void
    {
        $schema   = require APPPATH . 'Database/Schema/core_schema.php';
        $existing = array_flip($this->db->listTables());
        $created  = [];

        foreach ($schema['tables'] as $table => $ddl) {
            if (isset($existing[$table])) {
                continue;
            }
            $this->db->query($ddl);
            $created[$table] = true;
        }

        // Only add foreign keys to tables this run created. An existing table
        // already has whatever keys it was given.
        foreach ($schema['foreignKeys'] as $ddl) {
            if (preg_match('/^ALTER TABLE `(\w+)`/', $ddl, $m) && isset($created[$m[1]])) {
                $this->db->query($ddl);
            }
        }
    }

    public function down(): void
    {
        // Rolling this back would drop every table holding business data. It is
        // allowed only against the test database, never in development or
        // production, whatever command was typed.
        if (ENVIRONMENT !== 'testing') {
            throw new RuntimeException(
                'Refusing to drop the core schema outside the testing environment. '
                . 'Restore from a backup instead; see docs/restore.md.',
            );
        }

        $this->db->disableForeignKeyChecks();
        $schema = require APPPATH . 'Database/Schema/core_schema.php';
        foreach (array_keys($schema['tables']) as $table) {
            $this->forge->dropTable($table, true);
        }
        $this->db->enableForeignKeyChecks();
    }
}
