<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Core: organisation, identity, access control, audit trail and settings (module M1).
 *
 * Generated from database/schema/qms_schema.sql (the reviewed reference schema).
 * Raw SQL is used because CHECK constraints, generated columns, composite foreign
 * keys and triggers cannot be expressed with Forge. tests/database/SchemaParityTest
 * fails if the migrated schema ever differs from the reference file.
 */
class CreateCoreTables extends Migration
{
    /** Tables owned by this migration, in creation order. */
    private const TABLES = ['departments', 'employees', 'roles', 'permissions', 'role_permissions', 'users', 'user_password_history', 'audit_logs', 'system_settings'];

    /** DDL in execution order (tables, then the integrity triggers of each table). */
    private const STATEMENTS = [
        // departments
        <<<'SQL'
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
  COMMENT='Departments / production areas'
SQL,
        // employees
        <<<'SQL'
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
  COMMENT='People who inspect, set up, operate or approve (with or without a login)'
SQL,
        // roles
        <<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        // permissions
        <<<'SQL'
CREATE TABLE permissions (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code         VARCHAR(60)  NOT NULL COMMENT 'Checked in code, e.g. inspection.approve_qa; seeded by migrations only',
  module       VARCHAR(40)  NOT NULL,
  description  VARCHAR(255) NOT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_code (code),
  KEY idx_permissions_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        // role_permissions
        <<<'SQL'
CREATE TABLE role_permissions (
  role_id        INT UNSIGNED NOT NULL,
  permission_id  INT UNSIGNED NOT NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by     INT UNSIGNED NULL,
  PRIMARY KEY (role_id, permission_id),
  KEY idx_role_permissions_permission (permission_id),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        // users
        <<<'SQL'
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
  COMMENT='Login accounts. Never deleted: disable instead'
SQL,
        // user_password_history
        <<<'SQL'
CREATE TABLE user_password_history (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_password_history_user (user_id, created_at),
  CONSTRAINT fk_user_password_history_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Previous password hashes, used to block password reuse'
SQL,
        // audit_logs
        <<<'SQL'
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
  COMMENT='Append-only audit trail: UPDATE/DELETE blocked by triggers and by grants'
SQL,
        // trg_audit_logs_no_update
        <<<'SQL'
CREATE TRIGGER trg_audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: audit_logs is append-only';
END
SQL,
        // trg_audit_logs_no_delete
        <<<'SQL'
CREATE TRIGGER trg_audit_logs_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QMS-LOCK: audit_logs is append-only';
END
SQL,
        // system_settings
        <<<'SQL'
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
  COMMENT='Non-secret configuration. Credentials live in environment variables, never here'
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
