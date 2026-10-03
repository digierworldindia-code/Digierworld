<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Authentication: database sessions and login history (module M2).
 *
 * Generated from database/schema/qms_schema.sql (the reviewed reference schema).
 * Raw SQL is used because CHECK constraints, generated columns, composite foreign
 * keys and triggers cannot be expressed with Forge. tests/database/SchemaParityTest
 * fails if the migrated schema ever differs from the reference file.
 */
class CreateAuthTables extends Migration
{
    /** Tables owned by this migration, in creation order. */
    private const TABLES = ['ci_sessions', 'login_logs'];

    /** DDL in execution order (tables, then the integrity triggers of each table). */
    private const STATEMENTS = [
        // ci_sessions
        <<<'SQL'
CREATE TABLE ci_sessions (
  id          VARCHAR(128) NOT NULL,
  ip_address  VARCHAR(45)  NOT NULL,
  timestamp   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  data        BLOB         NOT NULL,
  PRIMARY KEY (id),
  KEY ci_sessions_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='CodeIgniter 4 DatabaseHandler session store'
SQL,
        // login_logs
        <<<'SQL'
CREATE TABLE login_logs (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id             INT UNSIGNED NULL COMMENT 'NULL when the username did not match an account',
  username_attempted  VARCHAR(100) NOT NULL,
  event               ENUM('LOGIN_SUCCESS','LOGIN_FAILED','LOGOUT','ACCOUNT_LOCKED','ACCOUNT_UNLOCKED',
                           'PASSWORD_CHANGED','PASSWORD_RESET','SESSION_TIMEOUT','SESSION_REVOKED') NOT NULL,
  failure_reason      VARCHAR(100) NULL COMMENT 'Internal only (UNKNOWN_USER, BAD_PASSWORD, LOCKED, DISABLED, THROTTLED)',
  ip_address          VARCHAR(45)  NOT NULL,
  user_agent          VARCHAR(255) NULL,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_logs_user (user_id, created_at),
  KEY idx_login_logs_ip (ip_address, created_at),
  KEY idx_login_logs_event (event, created_at),
  KEY idx_login_logs_created (created_at),
  CONSTRAINT fk_login_logs_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Append-only authentication history'
SQL,
        // trg_login_logs_no_update
        <<<'SQL'
CREATE TRIGGER trg_login_logs_no_update BEFORE UPDATE ON login_logs FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: login_logs is append-only';
END
SQL,
        // trg_login_logs_no_delete
        <<<'SQL'
CREATE TRIGGER trg_login_logs_no_delete BEFORE DELETE ON login_logs FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: login_logs is append-only';
END
SQL,
    ];

    public function up(): void
    {
        foreach (self::STATEMENTS as $statement) {
            $this->db->query($statement);
        }
    }

    public function down(): void
    {
        // Dropping a table also drops its triggers.
        foreach (array_reverse(self::TABLES) as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }
}
