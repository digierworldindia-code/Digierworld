<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Report types, numbering and versioned inspection templates (module M7).
 *
 * Generated from database/schema/qms_schema.sql (the reviewed reference schema).
 * Raw SQL is used because CHECK constraints, generated columns, composite foreign
 * keys and triggers cannot be expressed with Forge. tests/database/SchemaParityTest
 * fails if the migrated schema ever differs from the reference file.
 */
class CreateTemplateTables extends Migration
{
    /** Tables owned by this migration, in creation order. */
    private const TABLES = ['report_types', 'document_sequences', 'inspection_templates', 'template_sections', 'template_parameters', 'template_part_map', 'template_machine_map'];

    /** DDL in execution order (tables, then the integrity triggers of each table). */
    private const STATEMENTS = [
        // report_types
        <<<'SQL'
CREATE TABLE report_types (
  id                                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                              VARCHAR(10)  NOT NULL COMMENT 'SCA, PPI, IPR ...',
  name                              VARCHAR(100) NOT NULL,
  layout                            ENUM('SECTIONED','METHOD_MATRIX','SHIFT_GRID') NOT NULL
                                    COMMENT 'Entry + print layout. SHIFT_GRID = shifts x inspection times',
  doc_prefix                        VARCHAR(10)  NOT NULL COMMENT 'Report number prefix (system setting "Report prefixes")',
  header_shift                      ENUM('REQUIRED','OPTIONAL','HIDDEN') NOT NULL DEFAULT 'REQUIRED',
  header_operator                   ENUM('REQUIRED','OPTIONAL','HIDDEN') NOT NULL DEFAULT 'REQUIRED',
  header_setter                     ENUM('REQUIRED','OPTIONAL','HIDDEN') NOT NULL DEFAULT 'HIDDEN',
  header_timing                     ENUM('REQUIRED','OPTIONAL','HIDDEN') NOT NULL DEFAULT 'HIDDEN'
                                    COMMENT 'Received time / Finish time',
  requires_production_verification  BOOLEAN      NOT NULL DEFAULT 1,
  requires_quality_verification     BOOLEAN      NOT NULL DEFAULT 1,
  requires_qa_approval              BOOLEAN      NOT NULL DEFAULT 1,
  is_active                         BOOLEAN      NOT NULL DEFAULT 1,
  created_at                        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by                        INT UNSIGNED NULL,
  updated_at                        DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  updated_by                        INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_report_types_code (code),
  UNIQUE KEY uq_report_types_prefix (doc_prefix),
  CONSTRAINT chk_report_types_prefix CHECK (REGEXP_LIKE(doc_prefix, '^[A-Z0-9]{2,10}$', 'c')),
  CONSTRAINT chk_report_types_stages CHECK (requires_production_verification + requires_quality_verification
                                            + requires_qa_approval >= 1),
  CONSTRAINT chk_report_types_grid_shift CHECK (layout <> 'SHIFT_GRID' OR header_shift = 'HIDDEN')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Report families; the approval workflow stages are configured per type'
SQL,
        // document_sequences
        <<<'SQL'
CREATE TABLE document_sequences (
  report_type_id  INT UNSIGNED NOT NULL,
  period_key      VARCHAR(7)   NOT NULL COMMENT 'YYYY-MM, YYYY or ALL depending on documents.sequence_reset',
  last_number     INT UNSIGNED NOT NULL,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (report_type_id, period_key),
  CONSTRAINT fk_document_sequences_type FOREIGN KEY (report_type_id) REFERENCES report_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Gap-free report number counters, incremented inside the submit transaction'
SQL,
        // inspection_templates
        <<<'SQL'
CREATE TABLE inspection_templates (
  id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_type_id            INT UNSIGNED NOT NULL,
  template_code             VARCHAR(40)  NOT NULL,
  version                   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  name                      VARCHAR(150) NOT NULL,
  status                    ENUM('DRAFT','PUBLISHED','RETIRED') NOT NULL DEFAULT 'DRAFT',
  format_doc_no             VARCHAR(40)  NOT NULL COMMENT 'Controlled format number printed on the sheet, e.g. QA/F/12',
  format_rev_no             VARCHAR(10)  NOT NULL DEFAULT '00',
  format_made_date          DATE         NOT NULL COMMENT 'Make date of the format',
  format_rev_date           DATE         NULL COMMENT 'Revision date of the format',
  planned_rounds_per_shift  TINYINT UNSIGNED NULL COMMENT 'SHIFT_GRID only: inspection times pre-created per shift',
  notes                     TEXT         NULL,
  cloned_from_id            INT UNSIGNED NULL,
  published_at              DATETIME     NULL,
  published_by              INT UNSIGNED NULL,
  retired_at                DATETIME     NULL,
  published_code            VARCHAR(40)  GENERATED ALWAYS AS (IF(status = 'PUBLISHED', template_code, NULL)) VIRTUAL
                            COMMENT 'Unique: at most one PUBLISHED version per template code',
  created_at                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by                INT UNSIGNED NULL,
  updated_at                DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  updated_by                INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inspection_templates_code_version (template_code, version),
  UNIQUE KEY uq_inspection_templates_published (published_code),
  UNIQUE KEY uq_inspection_templates_id_type (id, report_type_id),
  KEY idx_inspection_templates_type_status (report_type_id, status),
  KEY idx_inspection_templates_cloned_from (cloned_from_id),
  KEY idx_inspection_templates_published_by (published_by),
  CONSTRAINT fk_inspection_templates_type FOREIGN KEY (report_type_id) REFERENCES report_types (id),
  CONSTRAINT fk_inspection_templates_cloned_from FOREIGN KEY (cloned_from_id) REFERENCES inspection_templates (id),
  CONSTRAINT fk_inspection_templates_published_by FOREIGN KEY (published_by) REFERENCES users (id),
  CONSTRAINT chk_inspection_templates_rev_date CHECK (format_rev_date IS NULL OR format_rev_date >= format_made_date),
  CONSTRAINT chk_inspection_templates_published CHECK (status = 'DRAFT' OR (published_at IS NOT NULL AND published_by IS NOT NULL)),
  CONSTRAINT chk_inspection_templates_retired CHECK ((status = 'RETIRED') = (retired_at IS NOT NULL)),
  CONSTRAINT chk_inspection_templates_rounds CHECK (planned_rounds_per_shift IS NULL OR planned_rounds_per_shift BETWEEN 1 AND 24)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Versioned inspection templates. PUBLISHED/RETIRED versions are immutable (triggers)'
SQL,
        // trg_inspection_templates_lock
        <<<'SQL'
CREATE TRIGGER trg_inspection_templates_lock BEFORE UPDATE ON inspection_templates FOR EACH ROW
BEGIN
  IF OLD.status <> 'DRAFT' THEN
    IF NOT (NEW.id <=> OLD.id AND NEW.report_type_id <=> OLD.report_type_id
            AND NEW.template_code <=> OLD.template_code AND NEW.version <=> OLD.version
            AND NEW.name <=> OLD.name AND NEW.format_doc_no <=> OLD.format_doc_no
            AND NEW.format_rev_no <=> OLD.format_rev_no AND NEW.format_made_date <=> OLD.format_made_date
            AND NEW.format_rev_date <=> OLD.format_rev_date
            AND NEW.planned_rounds_per_shift <=> OLD.planned_rounds_per_shift
            AND NEW.notes <=> OLD.notes AND NEW.cloned_from_id <=> OLD.cloned_from_id
            AND NEW.published_at <=> OLD.published_at AND NEW.published_by <=> OLD.published_by
            AND NEW.created_at <=> OLD.created_at AND NEW.created_by <=> OLD.created_by) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: published template content is read-only';
    END IF;
    IF NOT (NEW.status = OLD.status OR (OLD.status = 'PUBLISHED' AND NEW.status = 'RETIRED')) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: invalid template status change';
    END IF;
  END IF;
END
SQL,
        // trg_inspection_templates_no_delete
        <<<'SQL'
CREATE TRIGGER trg_inspection_templates_no_delete BEFORE DELETE ON inspection_templates FOR EACH ROW
BEGIN
  IF OLD.status <> 'DRAFT' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: only draft templates can be deleted';
  END IF;
END
SQL,
        // template_sections
        <<<'SQL'
CREATE TABLE template_sections (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  template_id  INT UNSIGNED NOT NULL,
  title        VARCHAR(120) NOT NULL COMMENT 'Operator Readiness, Machine Readiness, Process Parameters ...',
  description  VARCHAR(255) NULL,
  sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by   INT UNSIGNED NULL,
  updated_at   DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  updated_by   INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_template_sections_title (template_id, title),
  KEY idx_template_sections_sort (template_id, sort_order),
  CONSTRAINT fk_template_sections_template FOREIGN KEY (template_id) REFERENCES inspection_templates (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        // trg_template_sections_bi
        <<<'SQL'
CREATE TRIGGER trg_template_sections_bi BEFORE INSERT ON template_sections FOR EACH ROW
BEGIN
  IF (SELECT status FROM inspection_templates WHERE id = NEW.template_id) <> 'DRAFT' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: template is not a draft';
  END IF;
END
SQL,
        // trg_template_sections_bu
        <<<'SQL'
CREATE TRIGGER trg_template_sections_bu BEFORE UPDATE ON template_sections FOR EACH ROW
BEGIN
  IF (SELECT status FROM inspection_templates WHERE id = OLD.template_id) <> 'DRAFT'
     OR (SELECT status FROM inspection_templates WHERE id = NEW.template_id) <> 'DRAFT' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: template is not a draft';
  END IF;
END
SQL,
        // trg_template_sections_bd
        <<<'SQL'
CREATE TRIGGER trg_template_sections_bd BEFORE DELETE ON template_sections FOR EACH ROW
BEGIN
  IF (SELECT status FROM inspection_templates WHERE id = OLD.template_id) <> 'DRAFT' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: template is not a draft';
  END IF;
END
SQL,
        // template_parameters
        <<<'SQL'
CREATE TABLE template_parameters (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  section_id            INT UNSIGNED NOT NULL,
  parameter_id          INT UNSIGNED NOT NULL COMMENT 'Library identity, used for cross-part analytics',
  name                  VARCHAR(150) NOT NULL COMMENT 'Copied from the library when added; frozen once published',
  specification_text    VARCHAR(150) NULL COMMENT 'Spec as on the drawing: "6.50 +/-0.05", "3/4-14 NPSM", "Free from burr"',
  observation_type      ENUM('NUMERIC','OK_NOT_OK','GO_NO_GO','VISUAL','TEXT','PERCENTAGE','DATE','TIME') NOT NULL,
  unit_id               INT UNSIGNED NULL,
  nominal               DECIMAL(18,6) NULL,
  lsl                   DECIMAL(18,6) NULL COMMENT 'Lower specification limit (inclusive)',
  usl                   DECIMAL(18,6) NULL COMMENT 'Upper specification limit (inclusive)',
  decimal_places        TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'Max decimals accepted for NUMERIC / PERCENTAGE input',
  date_rule             ENUM('NONE','ON_OR_AFTER_INSPECTION_DATE') NOT NULL DEFAULT 'NONE'
                        COMMENT 'DATE only: e.g. PM due date must not be in the past',
  inspection_method_id  INT UNSIGNED NULL,
  gauge_type_id         INT UNSIGNED NULL COMMENT 'Gauge / instrument required',
  gauge_required        BOOLEAN      NOT NULL DEFAULT 0 COMMENT 'Inspector must record the Gauge ID used',
  observation_count     TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT 'Readings per round (Observation 1, Observation 2 ...)',
  is_mandatory          BOOLEAN      NOT NULL DEFAULT 1,
  prefill_source        ENUM('NONE','OPERATOR_SKILL_LEVEL','MACHINE_PM_DUE_DATE') NOT NULL DEFAULT 'NONE',
  help_text             VARCHAR(255) NULL,
  sort_order            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by            INT UNSIGNED NULL,
  updated_at            DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  updated_by            INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_template_parameters_sort (section_id, sort_order),
  KEY idx_template_parameters_parameter (parameter_id),
  KEY idx_template_parameters_unit (unit_id),
  KEY idx_template_parameters_method (inspection_method_id),
  KEY idx_template_parameters_gauge_type (gauge_type_id),
  CONSTRAINT fk_template_parameters_section FOREIGN KEY (section_id) REFERENCES template_sections (id) ON DELETE CASCADE,
  CONSTRAINT fk_template_parameters_parameter FOREIGN KEY (parameter_id) REFERENCES inspection_parameters (id),
  CONSTRAINT fk_template_parameters_unit FOREIGN KEY (unit_id) REFERENCES units (id),
  CONSTRAINT fk_template_parameters_method FOREIGN KEY (inspection_method_id) REFERENCES inspection_methods (id),
  CONSTRAINT fk_template_parameters_gauge_type FOREIGN KEY (gauge_type_id) REFERENCES gauge_types (id),
  CONSTRAINT chk_template_parameters_limits CHECK (lsl IS NULL OR usl IS NULL OR lsl <= usl),
  CONSTRAINT chk_template_parameters_limit_types CHECK (observation_type IN ('NUMERIC','PERCENTAGE')
                                                       OR (lsl IS NULL AND usl IS NULL AND nominal IS NULL)),
  CONSTRAINT chk_template_parameters_percentage CHECK (observation_type <> 'PERCENTAGE'
                                                      OR ((lsl IS NULL OR lsl BETWEEN 0 AND 100)
                                                          AND (usl IS NULL OR usl BETWEEN 0 AND 100))),
  CONSTRAINT chk_template_parameters_nominal CHECK (nominal IS NULL OR ((lsl IS NULL OR nominal >= lsl)
                                                                       AND (usl IS NULL OR nominal <= usl))),
  CONSTRAINT chk_template_parameters_decimals CHECK (decimal_places <= 6),
  CONSTRAINT chk_template_parameters_obs_count CHECK (observation_count BETWEEN 1 AND 10),
  CONSTRAINT chk_template_parameters_date_rule CHECK (date_rule = 'NONE' OR observation_type = 'DATE'),
  CONSTRAINT chk_template_parameters_gauge CHECK (gauge_required = 0 OR gauge_type_id IS NOT NULL),
  CONSTRAINT chk_template_parameters_prefill CHECK (prefill_source = 'NONE'
                                                   OR (prefill_source = 'OPERATOR_SKILL_LEVEL' AND observation_type = 'NUMERIC')
                                                   OR (prefill_source = 'MACHINE_PM_DUE_DATE' AND observation_type = 'DATE'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Specification of one parameter inside a template section. Operators can never edit LSL/USL'
SQL,
        // trg_template_parameters_bi
        <<<'SQL'
CREATE TRIGGER trg_template_parameters_bi BEFORE INSERT ON template_parameters FOR EACH ROW
BEGIN
  IF (SELECT t.status FROM template_sections s JOIN inspection_templates t ON t.id = s.template_id
      WHERE s.id = NEW.section_id) <> 'DRAFT' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: template is not a draft';
  END IF;
END
SQL,
        // trg_template_parameters_bu
        <<<'SQL'
CREATE TRIGGER trg_template_parameters_bu BEFORE UPDATE ON template_parameters FOR EACH ROW
BEGIN
  IF (SELECT t.status FROM template_sections s JOIN inspection_templates t ON t.id = s.template_id
      WHERE s.id = OLD.section_id) <> 'DRAFT'
     OR (SELECT t.status FROM template_sections s JOIN inspection_templates t ON t.id = s.template_id
         WHERE s.id = NEW.section_id) <> 'DRAFT' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: template is not a draft';
  END IF;
END
SQL,
        // trg_template_parameters_bd
        <<<'SQL'
CREATE TRIGGER trg_template_parameters_bd BEFORE DELETE ON template_parameters FOR EACH ROW
BEGIN
  IF (SELECT t.status FROM template_sections s JOIN inspection_templates t ON t.id = s.template_id
      WHERE s.id = OLD.section_id) <> 'DRAFT' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: template is not a draft';
  END IF;
END
SQL,
        // template_part_map
        <<<'SQL'
CREATE TABLE template_part_map (
  template_id  INT UNSIGNED NOT NULL,
  part_id      INT UNSIGNED NOT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by   INT UNSIGNED NULL,
  PRIMARY KEY (template_id, part_id),
  KEY idx_template_part_map_part (part_id),
  CONSTRAINT fk_template_part_map_template FOREIGN KEY (template_id) REFERENCES inspection_templates (id) ON DELETE CASCADE,
  CONSTRAINT fk_template_part_map_part FOREIGN KEY (part_id) REFERENCES parts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Parts a template applies to (no rows = all parts)'
SQL,
        // template_machine_map
        <<<'SQL'
CREATE TABLE template_machine_map (
  template_id  INT UNSIGNED NOT NULL,
  machine_id   INT UNSIGNED NOT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by   INT UNSIGNED NULL,
  PRIMARY KEY (template_id, machine_id),
  KEY idx_template_machine_map_machine (machine_id),
  CONSTRAINT fk_template_machine_map_template FOREIGN KEY (template_id) REFERENCES inspection_templates (id) ON DELETE CASCADE,
  CONSTRAINT fk_template_machine_map_machine FOREIGN KEY (machine_id) REFERENCES machines (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Machines a template applies to (no rows = all machines)'
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
