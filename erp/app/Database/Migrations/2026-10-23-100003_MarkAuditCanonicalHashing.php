<?php

namespace App\Database\Migrations;

use App\Database\SqlMigration;

/**
 * Audit entries from here on hash their JSON data in a canonical form
 * (MySQL 8 rewrites JSON columns, so hashing the text as written could not
 * be re-checked there). Records the first such entry id so older entries
 * keep being verified the old way, and adds the row audit writers take
 * turns on (no deadlocks between concurrent saves). Adds two settings
 * rows; no other data.
 */
class MarkAuditCanonicalHashing extends SqlMigration
{
    public function up(): void
    {
        $next = (int) ($this->db->query('SELECT COALESCE(MAX(id), 0) + 1 n FROM audit_logs')->getRow()->n ?? 1);
        $this->db->query("INSERT IGNORE INTO app_settings (setting_key, setting_value, value_type) VALUES ('audit.canonical_from_id', ?, 'int')", [(string) $next]);
        $this->db->query("INSERT IGNORE INTO app_settings (setting_key, setting_value, value_type) VALUES ('audit.chain_lock', '1', 'int')");
    }

    public function down(): void
    {
        $this->db->query("DELETE FROM app_settings WHERE setting_key IN ('audit.canonical_from_id', 'audit.chain_lock')");
    }
}
