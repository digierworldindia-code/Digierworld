<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Master data: shifts, units, methods, gauge types, machines, parts, parameter library (module M5).
 *
 * Generated from database/schema/qms_schema.sql (the reviewed reference schema).
 * Raw SQL is used because CHECK constraints, generated columns, composite foreign
 * keys and triggers cannot be expressed with Forge. tests/database/SchemaParityTest
 * fails if the migrated schema ever differs from the reference file.
 */
class CreateMasterDataTables extends Migration
{
    /** Tables owned by this migration, in creation order. */
    private const TABLES = ['shifts', 'units', 'inspection_methods', 'gauge_types', 'machines', 'parts', 'inspection_parameters'];

    /** DDL in execution order (tables, then the integrity triggers of each table). */
    private const STATEMENTS = [
        // shifts
        <<<'SQL'
CREATE TABLE shifts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(5)   NOT NULL COMMENT 'A / B / C',
  name        VARCHAR(30)  NOT NULL,
  start_time  TIME         NOT NULL,
  end_time    TIME         NOT NULL COMMENT 'end_time < start_time means the shift crosses midnight',
  sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  is_active   BOOLEAN      NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by  INT UNSIGNED NULL,
  updated_at  DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  updated_by  INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_shifts_code (code),
  CONSTRAINT chk_shifts_times CHECK (start_time <> end_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        // units
        <<<'SQL'
CREATE TABLE units (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(20)  NOT NULL,
  symbol      VARCHAR(20)  NOT NULL,
  name        VARCHAR(60)  NOT NULL,
  is_active   BOOLEAN      NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by  INT UNSIGNED NULL,
  updated_at  DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  updated_by  INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_units_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Units of measure (mm, kg, bar, %, ...)'
SQL,
        // inspection_methods
        <<<'SQL'
CREATE TABLE inspection_methods (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code         VARCHAR(20)  NOT NULL,
  name         VARCHAR(80)  NOT NULL,
  short_label  VARCHAR(12)  NOT NULL COMMENT 'Column caption on printed sheets: GO/NO GO, VISUAL, TPG, DVC',
  sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active    BOOLEAN      NOT NULL DEFAULT 1,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by   INT UNSIGNED NULL,
  updated_at   DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  updated_by   INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inspection_methods_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        // gauge_types
        <<<'SQL'
CREATE TABLE gauge_types (
  id                                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                                VARCHAR(30)  NOT NULL,
  name                                VARCHAR(100) NOT NULL,
  requires_calibration                BOOLEAN      NOT NULL DEFAULT 1,
  default_calibration_frequency_days  SMALLINT UNSIGNED NULL,
  is_active                           BOOLEAN      NOT NULL DEFAULT 1,
  created_at                          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by                          INT UNSIGNED NULL,
  updated_at                          DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  updated_by                          INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gauge_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Instrument categories: DVC, thread plug gauge, pressure gauge, refractometer ...'
SQL,
        // machines
        <<<'SQL'
CREATE TABLE machines (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  machine_code   VARCHAR(30)  NOT NULL COMMENT 'Machine Number as printed on sheets',
  machine_name   VARCHAR(120) NOT NULL,
  department_id  INT UNSIGNED NULL,
  make           VARCHAR(80)  NULL,
  model          VARCHAR(80)  NULL,
  location       VARCHAR(80)  NULL,
  pm_due_date    DATE         NULL COMMENT 'Next preventive maintenance due date; pre-fills Setup Change "PM Due Date"',
  is_active      BOOLEAN      NOT NULL DEFAULT 1,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by     INT UNSIGNED NULL,
  updated_at     DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  updated_by     INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_machines_code (machine_code),
  KEY idx_machines_department (department_id),
  CONSTRAINT fk_machines_department FOREIGN KEY (department_id) REFERENCES departments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        // parts
        <<<'SQL'
CREATE TABLE parts (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  part_number       VARCHAR(50)  NOT NULL,
  part_name         VARCHAR(150) NOT NULL,
  drawing_number    VARCHAR(60)  NULL,
  drawing_revision  VARCHAR(10)  NULL,
  customer_name     VARCHAR(120) NULL,
  material          VARCHAR(80)  NULL,
  is_active         BOOLEAN      NOT NULL DEFAULT 1,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by        INT UNSIGNED NULL,
  updated_at        DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  updated_by        INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_parts_number (part_number),
  KEY idx_parts_name (part_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        // inspection_parameters
        <<<'SQL'
CREATE TABLE inspection_parameters (
  id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                      VARCHAR(40)  NOT NULL COMMENT 'Stable identity across templates/versions, e.g. THROAT_DIA',
  name                      VARCHAR(150) NOT NULL,
  default_observation_type  ENUM('NUMERIC','OK_NOT_OK','GO_NO_GO','VISUAL','TEXT','PERCENTAGE','DATE','TIME') NOT NULL,
  default_unit_id           INT UNSIGNED NULL,
  default_method_id         INT UNSIGNED NULL,
  default_gauge_type_id     INT UNSIGNED NULL,
  description               VARCHAR(255) NULL,
  is_active                 BOOLEAN      NOT NULL DEFAULT 1,
  created_at                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by                INT UNSIGNED NULL,
  updated_at                DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  updated_by                INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inspection_parameters_code (code),
  KEY idx_inspection_parameters_name (name),
  KEY idx_inspection_parameters_unit (default_unit_id),
  KEY idx_inspection_parameters_method (default_method_id),
  KEY idx_inspection_parameters_gauge_type (default_gauge_type_id),
  CONSTRAINT fk_inspection_parameters_unit FOREIGN KEY (default_unit_id) REFERENCES units (id),
  CONSTRAINT fk_inspection_parameters_method FOREIGN KEY (default_method_id) REFERENCES inspection_methods (id),
  CONSTRAINT fk_inspection_parameters_gauge_type FOREIGN KEY (default_gauge_type_id) REFERENCES gauge_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Parameter library (Appearance, Throat Diameter, Chuck Pressure ...) reused by templates'
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
