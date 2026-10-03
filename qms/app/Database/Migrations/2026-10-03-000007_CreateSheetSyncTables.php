<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Google Sheets outbox / sync log (module M11).
 *
 * Generated from database/schema/qms_schema.sql (the reviewed reference schema).
 * Raw SQL is used because CHECK constraints, generated columns, composite foreign
 * keys and triggers cannot be expressed with Forge. tests/database/SchemaParityTest
 * fails if the migrated schema ever differs from the reference file.
 */
class CreateSheetSyncTables extends Migration
{
    /** Tables owned by this migration, in creation order. */
    private const TABLES = ['google_sheet_sync_logs'];

    /** DDL in execution order (tables, then the integrity triggers of each table). */
    private const STATEMENTS = [
        // google_sheet_sync_logs
        <<<'SQL'
CREATE TABLE google_sheet_sync_logs (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id        BIGINT UNSIGNED NOT NULL,
  job_type         ENUM('REPORT_UPSERT','OBSERVATIONS_APPEND') NOT NULL,
  spreadsheet_id   VARCHAR(100) NULL COMMENT 'Target spreadsheet, resolved from settings when the job is processed',
  sheet_name       VARCHAR(100) NOT NULL COMMENT 'Target tab, e.g. Reports or Observations_2026_10',
  sync_status      ENUM('PENDING_SYNC','PROCESSING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING_SYNC',
  attempt_count    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_attempt_at  DATETIME     NULL,
  locked_at        DATETIME     NULL,
  locked_by        VARCHAR(64)  NULL COMMENT 'Worker id (host:pid)',
  sheet_range      VARCHAR(120) NULL COMMENT 'A1 range written, e.g. Reports!A42:AH42',
  error_message    VARCHAR(2000) NULL COMMENT 'Last error, sanitised (never contains credentials)',
  synced_at        DATETIME     NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_google_sheet_sync_logs_queue (sync_status, next_attempt_at),
  KEY idx_google_sheet_sync_logs_report (report_id, job_type),
  CONSTRAINT fk_google_sheet_sync_logs_report FOREIGN KEY (report_id) REFERENCES inspection_reports (id),
  CONSTRAINT chk_google_sheet_sync_logs_synced CHECK ((sync_status = 'SYNCED') = (synced_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Transactional outbox + sync log for Google Sheets (MySQL stays the system of record)'
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
