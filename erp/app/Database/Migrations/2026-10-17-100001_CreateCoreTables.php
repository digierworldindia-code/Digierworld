<?php

namespace App\Database\Migrations;

use App\Database\SqlMigration;

/**
 * Core: user profiles, DB-backed RBAC, sessions, audit trail, notifications,
 * settings, private file metadata, financial years, exports, saved views.
 * Shield's own tables (users, auth_identities, auth_logins …) are created by
 * Shield's migrations, which run before this one.
 */
class CreateCoreTables extends SqlMigration
{
    public function up(): void
    {
        $a = self::AUDIT_COLS;

        $this->run([
            "CREATE TABLE financial_years (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(9) NOT NULL COMMENT 'e.g. 2026-27',
                short_code VARCHAR(5) NOT NULL COMMENT 'e.g. 26-27',
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                is_current TINYINT(1) NOT NULL DEFAULT 0,
                is_closed TINYINT(1) NOT NULL DEFAULT 0,
                closed_by INT UNSIGNED NULL,
                closed_at DATETIME NULL,
                {$a},
                UNIQUE KEY uq_fy_code (code),
                UNIQUE KEY uq_fy_start (start_date),
                CONSTRAINT chk_fy_dates CHECK (end_date > start_date)
            )",

            "CREATE TABLE user_profiles (
                user_id INT UNSIGNED NOT NULL PRIMARY KEY,
                full_name VARCHAR(150) NOT NULL,
                phone VARCHAR(20) NULL,
                designation VARCHAR(100) NULL,
                manager_user_id INT UNSIGNED NULL COMMENT 'reporting manager, drives team scope',
                employee_id BIGINT UNSIGNED NULL COMMENT 'FK added by HR migration',
                is_service_account TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'no interactive login',
                must_change_password TINYINT(1) NOT NULL DEFAULT 0,
                permissions_changed_at DATETIME NULL,
                last_seen_at DATETIME NULL,
                preferences JSON NULL,
                {$a},
                KEY idx_up_manager (manager_user_id),
                CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_up_manager FOREIGN KEY (manager_user_id) REFERENCES users(id) ON DELETE SET NULL
            )",

            "CREATE TABLE roles (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(60) NOT NULL,
                name VARCHAR(120) NOT NULL,
                description VARCHAR(500) NULL,
                is_super TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'implicitly holds every permission',
                is_system TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'starter role (still editable, not deletable if super)',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                {$a},
                UNIQUE KEY uq_roles_code (code)
            )",

            "CREATE TABLE permissions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                perm_key VARCHAR(100) NOT NULL,
                module VARCHAR(40) NOT NULL,
                description VARCHAR(255) NOT NULL,
                is_dangerous TINYINT(1) NOT NULL DEFAULT 0,
                scope VARCHAR(20) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_perm_key (perm_key),
                KEY idx_perm_module (module)
            )",

            "CREATE TABLE role_permissions (
                role_id INT UNSIGNED NOT NULL,
                permission_id INT UNSIGNED NOT NULL,
                granted_by INT UNSIGNED NULL,
                granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (role_id, permission_id),
                KEY idx_rp_perm (permission_id),
                CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
                CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
            )",

            "CREATE TABLE user_roles (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                role_id INT UNSIGNED NOT NULL,
                expires_at DATETIME NULL COMMENT 'time-limited roles e.g. auditor',
                assigned_by INT UNSIGNED NULL,
                assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_user_role (user_id, role_id),
                KEY idx_ur_role (role_id),
                CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
            )",

            "CREATE TABLE user_permission_overrides (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                permission_id INT UNSIGNED NOT NULL,
                effect ENUM('grant','deny') NOT NULL COMMENT 'deny always wins',
                reason VARCHAR(255) NOT NULL,
                expires_at DATETIME NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_upo (user_id, permission_id),
                CONSTRAINT fk_upo_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_upo_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
            )",

            "CREATE TABLE user_record_scopes (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                scope_type VARCHAR(40) NOT NULL COMMENT 'e.g. hr.employee — restricts to listed records when present',
                record_id BIGINT UNSIGNED NOT NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_urs (user_id, scope_type, record_id),
                CONSTRAINT fk_urs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )",

            "CREATE TABLE user_sessions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                session_id VARCHAR(128) NOT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_activity_at DATETIME NULL,
                revoked_at DATETIME NULL,
                revoked_by INT UNSIGNED NULL,
                revoke_reason VARCHAR(255) NULL,
                UNIQUE KEY uq_us_session (session_id),
                KEY idx_us_user (user_id, revoked_at),
                CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )",

            "CREATE TABLE audit_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                user_id INT UNSIGNED NULL,
                actor_type ENUM('user','system','cron','ai_service') NOT NULL DEFAULT 'user',
                action VARCHAR(80) NOT NULL,
                entity_type VARCHAR(60) NULL,
                entity_id BIGINT UNSIGNED NULL,
                summary VARCHAR(255) NULL,
                before_data JSON NULL,
                after_data JSON NULL,
                meta JSON NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                prev_hash CHAR(64) NULL,
                row_hash CHAR(64) NOT NULL COMMENT 'sha256 chain for tamper evidence',
                KEY idx_al_entity (entity_type, entity_id),
                KEY idx_al_user (user_id, occurred_at),
                KEY idx_al_action (action, occurred_at)
            )",

            "CREATE TABLE notifications (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                type VARCHAR(50) NOT NULL,
                title VARCHAR(200) NOT NULL,
                body VARCHAR(1000) NULL,
                link VARCHAR(255) NULL,
                entity_type VARCHAR(60) NULL,
                entity_id BIGINT UNSIGNED NULL,
                dedupe_key VARCHAR(150) NULL,
                read_at DATETIME NULL,
                emailed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_notif_dedupe (user_id, dedupe_key),
                KEY idx_notif_user (user_id, read_at, created_at),
                CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )",

            "CREATE TABLE app_settings (
                setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
                setting_value TEXT NULL,
                value_type ENUM('string','int','bool','json','decimal') NOT NULL DEFAULT 'string',
                updated_by INT UNSIGNED NULL,
                updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
            )",

            "CREATE TABLE integration_settings (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                integration VARCHAR(40) NOT NULL COMMENT 'smtp | google_sheets | ai',
                setting_key VARCHAR(80) NOT NULL,
                setting_value TEXT NULL COMMENT 'secrets stored encrypted (CI Encryption)',
                is_secret TINYINT(1) NOT NULL DEFAULT 0,
                updated_by INT UNSIGNED NULL,
                updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_is (integration, setting_key)
            )",

            "CREATE TABLE stored_files (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                disk_path VARCHAR(255) NOT NULL COMMENT 'relative to writable/uploads (outside webroot)',
                original_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                size_bytes BIGINT UNSIGNED NOT NULL,
                sha256 CHAR(64) NOT NULL,
                category VARCHAR(40) NOT NULL,
                entity_type VARCHAR(60) NULL,
                entity_id BIGINT UNSIGNED NULL,
                is_private TINYINT(1) NOT NULL DEFAULT 1,
                uploaded_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                UNIQUE KEY uq_sf_path (disk_path),
                KEY idx_sf_entity (entity_type, entity_id),
                KEY idx_sf_sha (sha256)
            )",

            "CREATE TABLE report_exports (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                report_code VARCHAR(60) NOT NULL,
                format ENUM('xlsx','csv','pdf') NOT NULL,
                filters JSON NULL,
                stored_file_id BIGINT UNSIGNED NULL,
                row_count INT UNSIGNED NULL,
                status ENUM('generated','failed') NOT NULL DEFAULT 'generated',
                downloaded_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_re_user (user_id, created_at),
                CONSTRAINT fk_re_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_re_file FOREIGN KEY (stored_file_id) REFERENCES stored_files(id) ON DELETE SET NULL
            )",

            "CREATE TABLE favorites (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                label VARCHAR(100) NOT NULL,
                url VARCHAR(255) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_fav (user_id, url),
                CONSTRAINT fk_fav_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )",

            "CREATE TABLE saved_views (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                module VARCHAR(60) NOT NULL,
                name VARCHAR(100) NOT NULL,
                filters JSON NULL,
                columns JSON NULL,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_sv (user_id, module),
                CONSTRAINT fk_sv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )",

            "CREATE TABLE draft_autosaves (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                form_key VARCHAR(100) NOT NULL,
                payload JSON NOT NULL,
                base_lock_version INT UNSIGNED NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_da (user_id, form_key),
                CONSTRAINT fk_da_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )",
        ]);
    }

    public function down(): void
    {
        $this->dropTables([
            'draft_autosaves', 'saved_views', 'favorites', 'report_exports', 'stored_files', 'integration_settings',
            'app_settings', 'notifications', 'audit_logs', 'user_sessions', 'user_record_scopes',
            'user_permission_overrides', 'user_roles', 'role_permissions', 'permissions', 'roles',
            'user_profiles', 'financial_years',
        ]);
    }
}
