<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Gauges and calibration history (module M6).
 *
 * Generated from database/schema/qms_schema.sql (the reviewed reference schema).
 * Raw SQL is used because CHECK constraints, generated columns, composite foreign
 * keys and triggers cannot be expressed with Forge. tests/database/SchemaParityTest
 * fails if the migrated schema ever differs from the reference file.
 */
class CreateGaugeTables extends Migration
{
    /** Tables owned by this migration, in creation order. */
    private const TABLES = ['gauges', 'gauge_calibrations'];

    /** DDL in execution order (tables, then the integrity triggers of each table). */
    private const STATEMENTS = [
        // gauges
        <<<'SQL'
CREATE TABLE gauges (
  id                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gauge_code                  VARCHAR(40)  NOT NULL COMMENT 'Gauge ID engraved / labelled on the instrument',
  gauge_name                  VARCHAR(120) NOT NULL,
  gauge_type_id               INT UNSIGNED NOT NULL,
  make                        VARCHAR(80)  NULL,
  serial_no                   VARCHAR(60)  NULL,
  measuring_range             VARCHAR(60)  NULL COMMENT 'e.g. 0-150 mm',
  least_count                 DECIMAL(12,6) NULL,
  unit_id                     INT UNSIGNED NULL,
  location                    VARCHAR(80)  NULL,
  calibration_frequency_days  SMALLINT UNSIGNED NULL,
  last_calibration_date       DATE         NULL COMMENT 'Maintained by GaugeService from gauge_calibrations',
  calibration_due_date        DATE         NULL COMMENT 'NULL only for gauge types that need no calibration',
  status                      ENUM('ACTIVE','IN_CALIBRATION','ON_HOLD','DAMAGED','RETIRED') NOT NULL DEFAULT 'ACTIVE',
  created_at                  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by                  INT UNSIGNED NULL,
  updated_at                  DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  updated_by                  INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gauges_code (gauge_code),
  KEY idx_gauges_type_status (gauge_type_id, status),
  KEY idx_gauges_due (calibration_due_date),
  KEY idx_gauges_unit (unit_id),
  CONSTRAINT fk_gauges_type FOREIGN KEY (gauge_type_id) REFERENCES gauge_types (id),
  CONSTRAINT fk_gauges_unit FOREIGN KEY (unit_id) REFERENCES units (id),
  CONSTRAINT chk_gauges_cal_dates CHECK (calibration_due_date IS NULL OR last_calibration_date IS NULL
                                         OR calibration_due_date > last_calibration_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        // gauge_calibrations
        <<<'SQL'
CREATE TABLE gauge_calibrations (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gauge_id            INT UNSIGNED NOT NULL,
  calibration_date    DATE         NOT NULL,
  due_date            DATE         NOT NULL,
  result              ENUM('ACCEPTED','ADJUSTED','REJECTED') NOT NULL,
  agency              VARCHAR(120) NULL,
  certificate_no      VARCHAR(60)  NULL,
  certificate_file    VARCHAR(255) NULL COMMENT 'Random file name under writable/uploads/certificates (never web-accessible)',
  certificate_mime    VARCHAR(100) NULL,
  certificate_sha256  CHAR(64)     NULL COMMENT 'Integrity hash of the stored certificate',
  remarks             VARCHAR(500) NULL,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by          INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY idx_gauge_calibrations_gauge (gauge_id, calibration_date),
  KEY idx_gauge_calibrations_created_by (created_by),
  CONSTRAINT fk_gauge_calibrations_gauge FOREIGN KEY (gauge_id) REFERENCES gauges (id),
  CONSTRAINT fk_gauge_calibrations_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT chk_gauge_calibrations_dates CHECK (due_date > calibration_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Append-only calibration history; corrections are new entries'
SQL,
        // trg_gauge_calibrations_no_update
        <<<'SQL'
CREATE TRIGGER trg_gauge_calibrations_no_update BEFORE UPDATE ON gauge_calibrations FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: calibration history is append-only';
END
SQL,
        // trg_gauge_calibrations_no_delete
        <<<'SQL'
CREATE TRIGGER trg_gauge_calibrations_no_delete BEFORE DELETE ON gauge_calibrations FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: calibration history is append-only';
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
