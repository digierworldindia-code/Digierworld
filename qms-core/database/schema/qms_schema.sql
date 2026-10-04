-- =============================================================================
--  QMS - Paperless Quality Inspection System
--  Reference schema for MySQL 8.0.16+ (target: MySQL 8.4 LTS), InnoDB, utf8mb4
-- =============================================================================
--  This file is the reviewed, canonical definition of the database. The
--  CodeIgniter 4 migrations in app/Database/Migrations must produce exactly this
--  schema (a CI check compares SHOW CREATE TABLE output of both).
--
--  Conventions
--  * Every DATETIME column is stored in UTC. The MySQL server runs with
--    default_time_zone = '+00:00'; the application converts to the plant time
--    zone (system setting regional.timezone) for display only.
--  * DATE columns that represent a production date (inspection_date) are stored
--    in plant-local terms: a night shift belongs to the date it started on.
--  * Master data is never hard-deleted once referenced (FK ... RESTRICT); it is
--    retired with is_active = 0. Codes (machine_code, part_number, gauge_code,
--    employee_code ...) are immutable after creation (enforced in the services).
--  * created_by / updated_by on master tables are plain columns (no FK) to avoid
--    circular dependencies; authoritative attribution lives in audit_logs and
--    inspection_approvals, which are FK-constrained. Business actors
--    (report creator, submitter, approver, round signers) ARE foreign keys.
--  * Constraint names are schema-unique (MySQL requirement for CHECK and FK).
--  * Integrity guards that must hold even if application code is wrong are
--    implemented as triggers at the end of this file (SQLSTATE 45000, messages
--    start with "QMS-LOCK:" so the application can map them to friendly errors).
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -----------------------------------------------------------------------------
-- 1. ORGANISATION, IDENTITY AND ACCESS CONTROL
-- -----------------------------------------------------------------------------

CREATE TABLE departments (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(20)  NOT NULL,
  name        VARCHAR(100) NOT NULL,
  is_active   BOOLEAN      NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by  INT UNSIGNED NULL,
  updated_at  DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  updated_by  INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_departments_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Departments / production areas';

CREATE TABLE employees (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_code  VARCHAR(30)  NOT NULL COMMENT 'HR employee ID, printed on reports and audit trail',
  full_name      VARCHAR(120) NOT NULL,
  department_id  INT UNSIGNED NULL,
  designation    VARCHAR(80)  NULL,
  skill_level    TINYINT UNSIGNED NULL COMMENT 'Skill-matrix level 0-4; pre-fills Setup Change "Skill Level"',
  phone          VARCHAR(20)  NULL,
  email          VARCHAR(150) NULL,
  is_active      BOOLEAN      NOT NULL DEFAULT 1,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by     INT UNSIGNED NULL,
  updated_at     DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  updated_by     INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_employees_code (employee_code),
  KEY idx_employees_department (department_id),
  KEY idx_employees_name (full_name),
  CONSTRAINT fk_employees_department FOREIGN KEY (department_id) REFERENCES departments (id),
  CONSTRAINT chk_employees_skill CHECK (skill_level IS NULL OR skill_level <= 4)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='People who inspect, set up, operate or approve (with or without a login)';

CREATE TABLE roles (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code            VARCHAR(30)  NOT NULL,
  name            VARCHAR(60)  NOT NULL,
  description     VARCHAR(255) NULL,
  is_system       BOOLEAN      NOT NULL DEFAULT 0 COMMENT 'Seeded role: cannot be deleted or have its code changed',
  is_super_admin  BOOLEAN      NOT NULL DEFAULT 0 COMMENT 'Implicitly holds every permission (still bound by workflow rules)',
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by      INT UNSIGNED NULL,
  updated_at      DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  updated_by      INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE permissions (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code         VARCHAR(60)  NOT NULL COMMENT 'Checked in code, e.g. inspection.approve_qa; seeded by migrations only',
  module       VARCHAR(40)  NOT NULL,
  description  VARCHAR(255) NOT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_code (code),
  KEY idx_permissions_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE role_permissions (
  role_id        INT UNSIGNED NOT NULL,
  permission_id  INT UNSIGNED NOT NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by     INT UNSIGNED NULL,
  PRIMARY KEY (role_id, permission_id),
  KEY idx_role_permissions_permission (permission_id),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE users (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id           INT UNSIGNED NULL COMMENT 'NULL only for the bootstrap super admin',
  role_id               INT UNSIGNED NOT NULL,
  username              VARCHAR(50)  NOT NULL,
  email                 VARCHAR(150) NULL,
  password_hash         VARCHAR(255) NOT NULL COMMENT 'password_hash(): Argon2id, bcrypt fallback',
  status                ENUM('ACTIVE','DISABLED') NOT NULL DEFAULT 'ACTIVE',
  must_change_password  BOOLEAN      NOT NULL DEFAULT 1,
  failed_login_count    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until          DATETIME     NULL COMMENT 'Temporary lockout after repeated failures',
  last_login_at         DATETIME     NULL,
  last_login_ip         VARCHAR(45)  NULL,
  password_changed_at   DATETIME     NULL,
  security_stamp        CHAR(32)     NOT NULL COMMENT 'Rotated on password, role or status change; invalidates live sessions',
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by            INT UNSIGNED NULL,
  updated_at            DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  updated_by            INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_employee (employee_id),
  KEY idx_users_role (role_id),
  CONSTRAINT fk_users_employee FOREIGN KEY (employee_id) REFERENCES employees (id),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Login accounts. Never deleted: disable instead';

CREATE TABLE user_password_history (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_password_history_user (user_id, created_at),
  CONSTRAINT fk_user_password_history_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Previous password hashes, used to block password reuse';

-- -----------------------------------------------------------------------------
-- 2. SESSIONS AND SECURITY LOGS
-- -----------------------------------------------------------------------------

CREATE TABLE ci_sessions (
  id          VARCHAR(128) NOT NULL,
  ip_address  VARCHAR(45)  NOT NULL,
  timestamp   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  data        BLOB         NOT NULL,
  PRIMARY KEY (id),
  KEY ci_sessions_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='CodeIgniter 4 DatabaseHandler session store';

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
  COMMENT='Append-only authentication history';

CREATE TABLE audit_logs (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED NULL COMMENT 'NULL = system / CLI job',
  employee_id     INT UNSIGNED NULL COMMENT 'Employee linked to the user at the time of the action',
  username        VARCHAR(50)  NULL COMMENT 'Snapshot so the entry stays readable if the account is renamed',
  action          VARCHAR(40)  NOT NULL COMMENT 'CREATE, UPDATE, SUBMIT, APPROVE, RETURN, REVISE, PRINT, EXPORT, SYNC_RETRY ...',
  module          VARCHAR(40)  NOT NULL COMMENT 'inspection, template, gauge, machine, user, role, settings ...',
  record_id       BIGINT UNSIGNED NULL,
  record_ref      VARCHAR(60)  NULL COMMENT 'Human reference: report number, gauge ID, part number ...',
  previous_value  JSON         NULL COMMENT 'Changed fields before the action (secrets redacted)',
  new_value       JSON         NULL COMMENT 'Changed fields after the action (secrets redacted)',
  ip_address      VARCHAR(45)  NULL,
  user_agent      VARCHAR(255) NULL,
  request_id      CHAR(32)     NULL COMMENT 'Groups the rows written by one HTTP request / CLI run',
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Date and time of the action (UTC)',
  PRIMARY KEY (id),
  KEY idx_audit_logs_module_record (module, record_id, created_at),
  KEY idx_audit_logs_user (user_id, created_at),
  KEY idx_audit_logs_employee (employee_id, created_at),
  KEY idx_audit_logs_action (action, created_at),
  KEY idx_audit_logs_created (created_at),
  KEY idx_audit_logs_ref (record_ref),
  CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_audit_logs_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Append-only audit trail: UPDATE/DELETE blocked by triggers and by grants';

-- -----------------------------------------------------------------------------
-- 3. SYSTEM SETTINGS
-- -----------------------------------------------------------------------------

CREATE TABLE system_settings (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  setting_group  VARCHAR(40)  NOT NULL COMMENT 'company, documents, regional, google, workflow, security, gauge, inspection',
  setting_key    VARCHAR(100) NOT NULL,
  setting_value  TEXT         NULL,
  value_type     ENUM('STRING','INTEGER','BOOLEAN','JSON','FILE') NOT NULL DEFAULT 'STRING',
  label          VARCHAR(120) NOT NULL,
  description    VARCHAR(255) NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  updated_by     INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_system_settings_key (setting_key),
  KEY idx_system_settings_group (setting_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Non-secret configuration. Credentials live in environment variables, never here';

-- -----------------------------------------------------------------------------
-- 4. MASTER DATA
-- -----------------------------------------------------------------------------

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
  COMMENT='Units of measure (mm, kg, bar, %, ...)';

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
  COMMENT='Instrument categories: DVC, thread plug gauge, pressure gauge, refractometer ...';

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
  COMMENT='Append-only calibration history; corrections are new entries';

-- -----------------------------------------------------------------------------
-- 5. REPORT TYPES, NUMBERING AND TEMPLATES
-- -----------------------------------------------------------------------------

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
  COMMENT='Report families; the approval workflow stages are configured per type';

CREATE TABLE document_sequences (
  report_type_id  INT UNSIGNED NOT NULL,
  period_key      VARCHAR(7)   NOT NULL COMMENT 'YYYY-MM, YYYY or ALL depending on documents.sequence_reset',
  last_number     INT UNSIGNED NOT NULL,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (report_type_id, period_key),
  CONSTRAINT fk_document_sequences_type FOREIGN KEY (report_type_id) REFERENCES report_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Gap-free report number counters, incremented inside the submit transaction';

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
  COMMENT='Parameter library (Appearance, Throat Diameter, Chuck Pressure ...) reused by templates';

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
  COMMENT='Versioned inspection templates. PUBLISHED/RETIRED versions are immutable (triggers)';

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
  COMMENT='Specification of one parameter inside a template section. Operators can never edit LSL/USL';

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
  COMMENT='Parts a template applies to (no rows = all parts)';

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
  COMMENT='Machines a template applies to (no rows = all machines)';

-- -----------------------------------------------------------------------------
-- 6. INSPECTION TRANSACTIONS
-- -----------------------------------------------------------------------------

CREATE TABLE inspection_reports (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_uuid           CHAR(36)     NOT NULL COMMENT 'Generated by the tablet when the inspection starts; makes creation idempotent',
  report_no             VARCHAR(30)  NULL COMMENT 'Allocated at first submission, e.g. SCA-2026-10-000001',
  revision_no           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  parent_report_id      BIGINT UNSIGNED NULL COMMENT 'The revision this one corrects',
  revision_reason       VARCHAR(500) NULL,
  is_current            BOOLEAN      NOT NULL DEFAULT 1 COMMENT 'The revision that represents this report number in dashboards',
  report_type_id        INT UNSIGNED NOT NULL,
  template_id           INT UNSIGNED NOT NULL COMMENT 'Exact template version used',
  part_id               INT UNSIGNED NOT NULL,
  machine_id            INT UNSIGNED NOT NULL,
  shift_id              INT UNSIGNED NULL COMMENT 'Header shift; NULL for SHIFT_GRID reports (shift per round)',
  inspection_date       DATE         NOT NULL COMMENT 'Production date',
  received_at           DATETIME     NULL COMMENT 'Received time (Setup Change Approval)',
  finish_at             DATETIME     NULL COMMENT 'Finish time (Setup Change Approval)',
  operator_employee_id  INT UNSIGNED NULL,
  setter_employee_id    INT UNSIGNED NULL,
  status                ENUM('DRAFT','PENDING_PRODUCTION','PENDING_QUALITY','PENDING_QA','QA_APPROVED',
                             'RETURNED','REJECTED','CANCELLED','SUPERSEDED') NOT NULL DEFAULT 'DRAFT',
  overall_result        ENUM('PASS','FAIL','INCOMPLETE') NULL COMMENT 'Computed server-side',
  oos_count             SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of out-of-specification readings',
  remarks               VARCHAR(1000) NULL,
  submitted_at          DATETIME     NULL,
  submitted_by          INT UNSIGNED NULL,
  approved_at           DATETIME     NULL COMMENT 'Final QA approval',
  approved_by           INT UNSIGNED NULL,
  lock_version          INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Optimistic locking for concurrent tablets',
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by            INT UNSIGNED NOT NULL,
  updated_at            DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  updated_by            INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inspection_reports_client_uuid (client_uuid),
  UNIQUE KEY uq_inspection_reports_no_rev (report_no, revision_no),
  KEY idx_inspection_reports_date (inspection_date),
  KEY idx_inspection_reports_current_date (is_current, inspection_date),
  KEY idx_inspection_reports_type_date (report_type_id, inspection_date),
  KEY idx_inspection_reports_template (template_id, report_type_id),
  KEY idx_inspection_reports_part_date (part_id, inspection_date),
  KEY idx_inspection_reports_machine_date (machine_id, inspection_date),
  KEY idx_inspection_reports_shift_date (shift_id, inspection_date),
  KEY idx_inspection_reports_operator_date (operator_employee_id, inspection_date),
  KEY idx_inspection_reports_setter (setter_employee_id),
  KEY idx_inspection_reports_status_date (status, inspection_date),
  KEY idx_inspection_reports_creator_status (created_by, status),
  KEY idx_inspection_reports_submitter_date (submitted_by, inspection_date),
  KEY idx_inspection_reports_approver (approved_by),
  KEY idx_inspection_reports_parent (parent_report_id),
  CONSTRAINT fk_inspection_reports_type FOREIGN KEY (report_type_id) REFERENCES report_types (id),
  CONSTRAINT fk_inspection_reports_template FOREIGN KEY (template_id, report_type_id)
             REFERENCES inspection_templates (id, report_type_id),
  CONSTRAINT fk_inspection_reports_part FOREIGN KEY (part_id) REFERENCES parts (id),
  CONSTRAINT fk_inspection_reports_machine FOREIGN KEY (machine_id) REFERENCES machines (id),
  CONSTRAINT fk_inspection_reports_shift FOREIGN KEY (shift_id) REFERENCES shifts (id),
  CONSTRAINT fk_inspection_reports_operator FOREIGN KEY (operator_employee_id) REFERENCES employees (id),
  CONSTRAINT fk_inspection_reports_setter FOREIGN KEY (setter_employee_id) REFERENCES employees (id),
  CONSTRAINT fk_inspection_reports_submitted_by FOREIGN KEY (submitted_by) REFERENCES users (id),
  CONSTRAINT fk_inspection_reports_approved_by FOREIGN KEY (approved_by) REFERENCES users (id),
  CONSTRAINT fk_inspection_reports_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_inspection_reports_parent FOREIGN KEY (parent_report_id) REFERENCES inspection_reports (id),
  CONSTRAINT chk_inspection_reports_revision CHECK ((revision_no = 0 AND parent_report_id IS NULL)
                                                   OR (revision_no > 0 AND parent_report_id IS NOT NULL
                                                       AND revision_reason IS NOT NULL)),
  CONSTRAINT chk_inspection_reports_number CHECK (status IN ('DRAFT','CANCELLED') OR report_no IS NOT NULL),
  CONSTRAINT chk_inspection_reports_submitted CHECK (status IN ('DRAFT','CANCELLED')
                                                    OR (submitted_at IS NOT NULL AND submitted_by IS NOT NULL)),
  CONSTRAINT chk_inspection_reports_approved CHECK (status NOT IN ('QA_APPROVED','SUPERSEDED')
                                                   OR (approved_at IS NOT NULL AND approved_by IS NOT NULL)),
  CONSTRAINT chk_inspection_reports_superseded CHECK (status <> 'SUPERSEDED' OR is_current = 0),
  CONSTRAINT chk_inspection_reports_timing CHECK (finish_at IS NULL OR received_at IS NULL OR finish_at >= received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inspection report header. Data is frozen once submitted; approved reports change only via revisions';

CREATE TABLE inspection_rounds (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id           BIGINT UNSIGNED NOT NULL,
  round_no            SMALLINT UNSIGNED NOT NULL COMMENT 'Single-round layouts always have exactly round 1',
  shift_id            INT UNSIGNED NULL COMMENT 'SHIFT_GRID only (Shift A / B / C)',
  inspection_time     TIME         NULL COMMENT 'SHIFT_GRID only: time of this inspection',
  status              ENUM('OPEN','SIGNED','VERIFIED') NOT NULL DEFAULT 'OPEN',
  lot_status          ENUM('ACCEPTED','REJECTED','REWORK','ON_HOLD') NULL,
  accepted_qty        INT UNSIGNED NULL,
  rejected_qty        INT UNSIGNED NULL,
  rework_qty          INT UNSIGNED NULL,
  operator_user_id    INT UNSIGNED NULL COMMENT 'Inspected By Operator (e-signature)',
  operator_signed_at  DATETIME     NULL,
  qe_user_id          INT UNSIGNED NULL COMMENT 'Inspected By Quality Engineer (e-signature)',
  qe_verified_at      DATETIME     NULL,
  remarks             VARCHAR(500) NULL,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by          INT UNSIGNED NULL,
  updated_at          DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  updated_by          INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inspection_rounds_no (report_id, round_no),
  UNIQUE KEY uq_inspection_rounds_id_report (id, report_id),
  KEY idx_inspection_rounds_shift (shift_id),
  KEY idx_inspection_rounds_operator (operator_user_id, operator_signed_at),
  KEY idx_inspection_rounds_qe (qe_user_id),
  CONSTRAINT fk_inspection_rounds_report FOREIGN KEY (report_id) REFERENCES inspection_reports (id) ON DELETE CASCADE,
  CONSTRAINT fk_inspection_rounds_shift FOREIGN KEY (shift_id) REFERENCES shifts (id),
  CONSTRAINT fk_inspection_rounds_operator FOREIGN KEY (operator_user_id) REFERENCES users (id),
  CONSTRAINT fk_inspection_rounds_qe FOREIGN KEY (qe_user_id) REFERENCES users (id),
  CONSTRAINT chk_inspection_rounds_signed CHECK (status = 'OPEN'
                                                OR (operator_user_id IS NOT NULL AND operator_signed_at IS NOT NULL)),
  CONSTRAINT chk_inspection_rounds_verified CHECK ((status = 'VERIFIED') = (qe_user_id IS NOT NULL AND qe_verified_at IS NOT NULL)),
  CONSTRAINT chk_inspection_rounds_distinct CHECK (qe_user_id IS NULL OR operator_user_id IS NULL OR qe_user_id <> operator_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inspection rounds: one per report for single layouts, one per shift/inspection time for SHIFT_GRID';

CREATE TABLE inspection_observations (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id              BIGINT UNSIGNED NOT NULL,
  round_id               BIGINT UNSIGNED NOT NULL,
  template_parameter_id  INT UNSIGNED NOT NULL,
  lsl                    DECIMAL(18,6) NULL COMMENT 'Specification snapshot copied server-side from the template',
  usl                    DECIMAL(18,6) NULL,
  gauge_id               INT UNSIGNED NULL COMMENT 'Gauge ID used',
  gauge_cal_due_date     DATE         NULL COMMENT 'Calibration due date of the gauge when it was used',
  gauge_cal_valid        BOOLEAN      NULL COMMENT '1 = gauge was ACTIVE and in calibration on the inspection date',
  result                 ENUM('PASS','FAIL','INCOMPLETE','NOT_APPLICABLE') NOT NULL DEFAULT 'INCOMPLETE',
  remarks                VARCHAR(500) NULL,
  created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by             INT UNSIGNED NULL,
  updated_at             DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  updated_by             INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inspection_observations_param (round_id, template_parameter_id),
  KEY idx_inspection_observations_round_report (round_id, report_id),
  KEY idx_inspection_observations_report_result (report_id, result),
  KEY idx_inspection_observations_parameter (template_parameter_id),
  KEY idx_inspection_observations_gauge (gauge_id),
  CONSTRAINT fk_inspection_observations_report FOREIGN KEY (report_id) REFERENCES inspection_reports (id) ON DELETE CASCADE,
  CONSTRAINT fk_inspection_observations_round FOREIGN KEY (round_id, report_id)
             REFERENCES inspection_rounds (id, report_id) ON DELETE CASCADE,
  CONSTRAINT fk_inspection_observations_parameter FOREIGN KEY (template_parameter_id) REFERENCES template_parameters (id),
  CONSTRAINT fk_inspection_observations_gauge FOREIGN KEY (gauge_id) REFERENCES gauges (id),
  CONSTRAINT chk_inspection_observations_limits CHECK (lsl IS NULL OR usl IS NULL OR lsl <= usl),
  CONSTRAINT chk_inspection_observations_gauge CHECK ((gauge_id IS NULL) = (gauge_cal_valid IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='One row per parameter per round: gauge used, remarks, spec snapshot and aggregated result';

CREATE TABLE inspection_readings (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  observation_id  BIGINT UNSIGNED NOT NULL,
  reading_no      TINYINT UNSIGNED NOT NULL COMMENT 'Observation 1, Observation 2 ...',
  value_numeric   DECIMAL(18,6) NULL COMMENT 'NUMERIC and PERCENTAGE',
  value_choice    ENUM('OK','NOT_OK','GO','NO_GO') NULL COMMENT 'OK_NOT_OK, VISUAL and GO_NO_GO',
  value_text      VARCHAR(255) NULL COMMENT 'TEXT',
  value_date      DATE         NULL COMMENT 'DATE',
  value_time      TIME         NULL COMMENT 'TIME',
  result          ENUM('PASS','FAIL','NOT_APPLICABLE') NULL COMMENT 'Computed server-side; NULL while empty',
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by      INT UNSIGNED NULL,
  updated_at      DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  updated_by      INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inspection_readings_no (observation_id, reading_no),
  CONSTRAINT fk_inspection_readings_observation FOREIGN KEY (observation_id)
             REFERENCES inspection_observations (id) ON DELETE CASCADE,
  CONSTRAINT chk_inspection_readings_no CHECK (reading_no BETWEEN 1 AND 10),
  CONSTRAINT chk_inspection_readings_single_value CHECK ((value_numeric IS NOT NULL) + (value_choice IS NOT NULL)
                                                        + (value_text IS NOT NULL) + (value_date IS NOT NULL)
                                                        + (value_time IS NOT NULL) <= 1),
  CONSTRAINT chk_inspection_readings_result CHECK ((result IS NULL) = (value_numeric IS NULL AND value_choice IS NULL
                                                                      AND value_text IS NULL AND value_date IS NULL
                                                                      AND value_time IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Individual readings with typed values and PASS/FAIL';

CREATE TABLE inspection_approvals (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id        BIGINT UNSIGNED NOT NULL,
  stage            ENUM('SUBMISSION','PRODUCTION_VERIFICATION','QUALITY_VERIFICATION','QA_APPROVAL',
                        'REVISION','CANCELLATION') NOT NULL,
  action           ENUM('SUBMITTED','APPROVED','RETURNED','REJECTED','CANCELLED','REVISION_CREATED','SUPERSEDED') NOT NULL,
  from_status      ENUM('DRAFT','PENDING_PRODUCTION','PENDING_QUALITY','PENDING_QA','QA_APPROVED',
                        'RETURNED','REJECTED','CANCELLED','SUPERSEDED') NOT NULL,
  to_status        ENUM('DRAFT','PENDING_PRODUCTION','PENDING_QUALITY','PENDING_QA','QA_APPROVED',
                        'RETURNED','REJECTED','CANCELLED','SUPERSEDED') NOT NULL,
  user_id          INT UNSIGNED NOT NULL COMMENT 'Signer',
  role_id          INT UNSIGNED NOT NULL COMMENT 'Role held when signing = meaning of the signature',
  remarks          VARCHAR(1000) NULL,
  reauthenticated  BOOLEAN      NOT NULL DEFAULT 0 COMMENT 'Signer re-entered the password for this signature',
  ip_address       VARCHAR(45)  NULL,
  signed_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_inspection_approvals_report (report_id, signed_at),
  KEY idx_inspection_approvals_user (user_id, signed_at),
  KEY idx_inspection_approvals_role (role_id),
  CONSTRAINT fk_inspection_approvals_report FOREIGN KEY (report_id) REFERENCES inspection_reports (id),
  CONSTRAINT fk_inspection_approvals_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_inspection_approvals_role FOREIGN KEY (role_id) REFERENCES roles (id),
  CONSTRAINT chk_inspection_approvals_remarks CHECK (action NOT IN ('RETURNED','REJECTED','CANCELLED','REVISION_CREATED')
                                                    OR remarks IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Append-only workflow signatures (who, what stage, when, why)';

-- -----------------------------------------------------------------------------
-- 7. INTEGRATION AND REQUEST SAFETY
-- -----------------------------------------------------------------------------

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
  COMMENT='Transactional outbox + sync log for Google Sheets (MySQL stays the system of record)';

CREATE TABLE idempotency_keys (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  idem_key       CHAR(36)     NOT NULL COMMENT 'UUID generated by the browser per action attempt',
  action         VARCHAR(50)  NOT NULL,
  request_hash   CHAR(64)     NOT NULL COMMENT 'SHA-256 of the request payload; same key + different payload is rejected',
  response_code  SMALLINT UNSIGNED NULL,
  response_body  JSON         NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_idempotency_keys_user_key (user_id, idem_key),
  KEY idx_idempotency_keys_created (created_at),
  CONSTRAINT fk_idempotency_keys_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Replay protection for submit / approve requests (purged after 48 h)';

-- -----------------------------------------------------------------------------
-- 8. INTEGRITY GUARDS (TRIGGERS)
-- -----------------------------------------------------------------------------
--  Run with a client that understands DELIMITER (mysql CLI). The migrations
--  execute each CREATE TRIGGER as a single statement, so no delimiter is needed
--  there. Foreign-key cascades do not fire triggers (MySQL behaviour), and the
--  application account has no DELETE privilege on the guarded parents anyway.

DELIMITER $$

-- 8.1 Append-only evidence tables --------------------------------------------

CREATE TRIGGER trg_audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: audit_logs is append-only';
END$$

CREATE TRIGGER trg_audit_logs_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: audit_logs is append-only';
END$$

CREATE TRIGGER trg_login_logs_no_update BEFORE UPDATE ON login_logs FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: login_logs is append-only';
END$$

CREATE TRIGGER trg_login_logs_no_delete BEFORE DELETE ON login_logs FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: login_logs is append-only';
END$$

CREATE TRIGGER trg_inspection_approvals_no_update BEFORE UPDATE ON inspection_approvals FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: approval signatures are append-only';
END$$

CREATE TRIGGER trg_inspection_approvals_no_delete BEFORE DELETE ON inspection_approvals FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: approval signatures are append-only';
END$$

CREATE TRIGGER trg_gauge_calibrations_no_update BEFORE UPDATE ON gauge_calibrations FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: calibration history is append-only';
END$$

CREATE TRIGGER trg_gauge_calibrations_no_delete BEFORE DELETE ON gauge_calibrations FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: calibration history is append-only';
END$$

-- 8.2 Published templates are controlled documents ---------------------------

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
END$$

CREATE TRIGGER trg_inspection_templates_no_delete BEFORE DELETE ON inspection_templates FOR EACH ROW
BEGIN
  IF OLD.status <> 'DRAFT' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: only draft templates can be deleted';
  END IF;
END$$

CREATE TRIGGER trg_template_sections_bi BEFORE INSERT ON template_sections FOR EACH ROW
BEGIN
  IF (SELECT status FROM inspection_templates WHERE id = NEW.template_id) <> 'DRAFT' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: template is not a draft';
  END IF;
END$$

CREATE TRIGGER trg_template_sections_bu BEFORE UPDATE ON template_sections FOR EACH ROW
BEGIN
  IF (SELECT status FROM inspection_templates WHERE id = OLD.template_id) <> 'DRAFT'
     OR (SELECT status FROM inspection_templates WHERE id = NEW.template_id) <> 'DRAFT' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: template is not a draft';
  END IF;
END$$

CREATE TRIGGER trg_template_sections_bd BEFORE DELETE ON template_sections FOR EACH ROW
BEGIN
  IF (SELECT status FROM inspection_templates WHERE id = OLD.template_id) <> 'DRAFT' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: template is not a draft';
  END IF;
END$$

CREATE TRIGGER trg_template_parameters_bi BEFORE INSERT ON template_parameters FOR EACH ROW
BEGIN
  IF (SELECT t.status FROM template_sections s JOIN inspection_templates t ON t.id = s.template_id
      WHERE s.id = NEW.section_id) <> 'DRAFT' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: template is not a draft';
  END IF;
END$$

CREATE TRIGGER trg_template_parameters_bu BEFORE UPDATE ON template_parameters FOR EACH ROW
BEGIN
  IF (SELECT t.status FROM template_sections s JOIN inspection_templates t ON t.id = s.template_id
      WHERE s.id = OLD.section_id) <> 'DRAFT'
     OR (SELECT t.status FROM template_sections s JOIN inspection_templates t ON t.id = s.template_id
         WHERE s.id = NEW.section_id) <> 'DRAFT' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: template is not a draft';
  END IF;
END$$

CREATE TRIGGER trg_template_parameters_bd BEFORE DELETE ON template_parameters FOR EACH ROW
BEGIN
  IF (SELECT t.status FROM template_sections s JOIN inspection_templates t ON t.id = s.template_id
      WHERE s.id = OLD.section_id) <> 'DRAFT' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: template is not a draft';
  END IF;
END$$

-- 8.3 Inspection data is frozen after submission -----------------------------
--  Editable states: DRAFT and RETURNED. Afterwards only workflow columns move
--  (status, is_current, approved_at/by, lock_version, updated_at/by), and a
--  final report can only go QA_APPROVED -> SUPERSEDED (when a revision is approved).

CREATE TRIGGER trg_inspection_reports_lock BEFORE UPDATE ON inspection_reports FOR EACH ROW
BEGIN
  IF OLD.status NOT IN ('DRAFT','RETURNED') THEN
    IF NOT (NEW.id <=> OLD.id AND NEW.client_uuid <=> OLD.client_uuid
            AND NEW.report_no <=> OLD.report_no AND NEW.revision_no <=> OLD.revision_no
            AND NEW.parent_report_id <=> OLD.parent_report_id AND NEW.revision_reason <=> OLD.revision_reason
            AND NEW.report_type_id <=> OLD.report_type_id AND NEW.template_id <=> OLD.template_id
            AND NEW.part_id <=> OLD.part_id AND NEW.machine_id <=> OLD.machine_id
            AND NEW.shift_id <=> OLD.shift_id AND NEW.inspection_date <=> OLD.inspection_date
            AND NEW.received_at <=> OLD.received_at AND NEW.finish_at <=> OLD.finish_at
            AND NEW.operator_employee_id <=> OLD.operator_employee_id
            AND NEW.setter_employee_id <=> OLD.setter_employee_id
            AND NEW.overall_result <=> OLD.overall_result AND NEW.oos_count <=> OLD.oos_count
            AND NEW.remarks <=> OLD.remarks AND NEW.submitted_at <=> OLD.submitted_at
            AND NEW.submitted_by <=> OLD.submitted_by
            AND NEW.created_at <=> OLD.created_at AND NEW.created_by <=> OLD.created_by) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: inspection data is read-only after submission';
    END IF;
    IF OLD.status IN ('QA_APPROVED','REJECTED','CANCELLED','SUPERSEDED')
       AND NOT (OLD.status = 'QA_APPROVED' AND NEW.status = 'SUPERSEDED' AND NEW.is_current = 0
                AND NEW.approved_at <=> OLD.approved_at AND NEW.approved_by <=> OLD.approved_by) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: finalised inspection report is read-only';
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_inspection_reports_no_delete BEFORE DELETE ON inspection_reports FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: inspection reports cannot be deleted (cancel instead)';
END$$

CREATE TRIGGER trg_inspection_rounds_bi BEFORE INSERT ON inspection_rounds FOR EACH ROW
BEGIN
  IF (SELECT status FROM inspection_reports WHERE id = NEW.report_id) NOT IN ('DRAFT','RETURNED') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: report is not editable';
  END IF;
END$$

CREATE TRIGGER trg_inspection_rounds_bu BEFORE UPDATE ON inspection_rounds FOR EACH ROW
BEGIN
  IF (SELECT status FROM inspection_reports WHERE id = OLD.report_id) NOT IN ('DRAFT','RETURNED')
     OR NOT (NEW.report_id <=> OLD.report_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: report is not editable';
  END IF;
END$$

CREATE TRIGGER trg_inspection_rounds_bd BEFORE DELETE ON inspection_rounds FOR EACH ROW
BEGIN
  IF (SELECT status FROM inspection_reports WHERE id = OLD.report_id) NOT IN ('DRAFT','RETURNED') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: report is not editable';
  END IF;
END$$

CREATE TRIGGER trg_inspection_observations_bi BEFORE INSERT ON inspection_observations FOR EACH ROW
BEGIN
  IF (SELECT status FROM inspection_reports WHERE id = NEW.report_id) NOT IN ('DRAFT','RETURNED') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: report is not editable';
  END IF;
END$$

CREATE TRIGGER trg_inspection_observations_bu BEFORE UPDATE ON inspection_observations FOR EACH ROW
BEGIN
  IF (SELECT status FROM inspection_reports WHERE id = OLD.report_id) NOT IN ('DRAFT','RETURNED')
     OR NOT (NEW.report_id <=> OLD.report_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: report is not editable';
  END IF;
END$$

CREATE TRIGGER trg_inspection_observations_bd BEFORE DELETE ON inspection_observations FOR EACH ROW
BEGIN
  IF (SELECT status FROM inspection_reports WHERE id = OLD.report_id) NOT IN ('DRAFT','RETURNED') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: report is not editable';
  END IF;
END$$

CREATE TRIGGER trg_inspection_readings_bi BEFORE INSERT ON inspection_readings FOR EACH ROW
BEGIN
  IF (SELECT r.status FROM inspection_observations o JOIN inspection_reports r ON r.id = o.report_id
      WHERE o.id = NEW.observation_id) NOT IN ('DRAFT','RETURNED') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: report is not editable';
  END IF;
END$$

CREATE TRIGGER trg_inspection_readings_bu BEFORE UPDATE ON inspection_readings FOR EACH ROW
BEGIN
  IF (SELECT r.status FROM inspection_observations o JOIN inspection_reports r ON r.id = o.report_id
      WHERE o.id = OLD.observation_id) NOT IN ('DRAFT','RETURNED')
     OR NOT (NEW.observation_id <=> OLD.observation_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: report is not editable';
  END IF;
END$$

CREATE TRIGGER trg_inspection_readings_bd BEFORE DELETE ON inspection_readings FOR EACH ROW
BEGIN
  IF (SELECT r.status FROM inspection_observations o JOIN inspection_reports r ON r.id = o.report_id
      WHERE o.id = OLD.observation_id) NOT IN ('DRAFT','RETURNED') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: report is not editable';
  END IF;
END$$

DELIMITER ;
