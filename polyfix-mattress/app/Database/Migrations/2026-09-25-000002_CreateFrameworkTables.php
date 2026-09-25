<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tables the CodeIgniter application needs that the previous platform did not.
 *
 *  ci_sessions          CodeIgniter's database session store.
 *  identifier_counters  Replaces PostgreSQL's five identifier sequences. A row is
 *                       locked with SELECT ... FOR UPDATE inside the issuing
 *                       transaction, so two concurrent requests can never be
 *                       given the same serial or claim number.
 *  audit_chain_head     The last link of the audit hash chain. Locking this one
 *                       row serialises audit appends, which replaces the
 *                       PostgreSQL advisory lock the old trigger used.
 */
class CreateFrameworkTables extends Migration
{
    public function up(): void
    {
        $existing = array_flip($this->db->listTables());

        if (! isset($existing['ci_sessions'])) {
            $this->db->query(<<<'SQL'
                CREATE TABLE `ci_sessions` (
                  `id` VARCHAR(128) NOT NULL,
                  `ip_address` VARCHAR(45) NOT NULL,
                  `timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `data` BLOB NOT NULL,
                  PRIMARY KEY (`id`),
                  KEY `ci_sessions_timestamp` (`timestamp`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
                SQL);
        }

        if (! isset($existing['identifier_counters'])) {
            $this->db->query(<<<'SQL'
                CREATE TABLE `identifier_counters` (
                  `name` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `value` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                  PRIMARY KEY (`name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
                SQL);
            // INSERT IGNORE: never resets a counter that already has a value.
            $this->db->query(<<<'SQL'
                INSERT IGNORE INTO `identifier_counters` (`name`, `value`)
                VALUES ('serial', 0), ('batch', 0), ('claim', 0), ('dispatch', 0), ('dealer', 0)
                SQL);
        }

        if (! isset($existing['audit_chain_head'])) {
            $this->db->query(<<<'SQL'
                CREATE TABLE `audit_chain_head` (
                  `id` TINYINT UNSIGNED NOT NULL,
                  `last_audit_id` BIGINT NULL,
                  `last_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
                  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                  PRIMARY KEY (`id`),
                  CONSTRAINT `audit_chain_head_single_row_chk` CHECK (`id` = 1)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
                SQL);
            // Seed the head from whatever the audit log already holds, so an
            // imported history keeps chaining from its real last link.
            $this->db->query(<<<'SQL'
                INSERT IGNORE INTO `audit_chain_head` (`id`, `last_audit_id`, `last_hash`)
                SELECT 1, a.id, a.row_hash FROM (
                  SELECT id, row_hash FROM audit_logs ORDER BY id DESC LIMIT 1
                ) a
                UNION ALL
                SELECT 1, NULL, NULL FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM audit_logs)
                SQL);
        }
    }

    public function down(): void
    {
        // These hold no business data; the counters are re-derivable with
        // `php spark polyfix:sync-counters`.
        $this->forge->dropTable('audit_chain_head', true);
        $this->forge->dropTable('identifier_counters', true);
        $this->forge->dropTable('ci_sessions', true);
    }
}
