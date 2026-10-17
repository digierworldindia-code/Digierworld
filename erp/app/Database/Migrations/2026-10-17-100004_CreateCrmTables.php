<?php

namespace App\Database\Migrations;

use App\Database\SqlMigration;

/**
 * Telecalling CRM (leads, timeline, calls, follow-ups, visits, meetings,
 * tasks, reminders, assignments, conversions, targets) and the Google Sheets
 * sync queue. MySQL is the source of truth; Sheets is a downstream mirror.
 */
class CreateCrmTables extends SqlMigration
{
    public function up(): void
    {
        $a = self::AUDIT_COLS;

        $this->run([
            "CREATE TABLE crm_stages (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(40) NOT NULL,
                name VARCHAR(80) NOT NULL,
                sort_order SMALLINT NOT NULL DEFAULT 0,
                is_won TINYINT(1) NOT NULL DEFAULT 0,
                is_lost TINYINT(1) NOT NULL DEFAULT 0,
                is_closed TINYINT(1) NOT NULL DEFAULT 0,
                is_dnd TINYINT(1) NOT NULL DEFAULT 0,
                color VARCHAR(9) NOT NULL DEFAULT '#6c757d',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                {$a},
                UNIQUE KEY uq_stage_code (code)
            )",

            "CREATE TABLE lead_sources (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(80) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                {$a},
                UNIQUE KEY uq_ls_name (name)
            )",

            "CREATE TABLE lost_reasons (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                {$a},
                UNIQUE KEY uq_lr_name (name)
            )",

            "CREATE TABLE leads (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                lead_code VARCHAR(20) NOT NULL,
                company_name VARCHAR(200) NOT NULL,
                contact_person VARCHAR(150) NULL,
                mobile VARCHAR(20) NOT NULL,
                mobile_normalized VARCHAR(15) NOT NULL,
                alt_mobile VARCHAR(20) NULL,
                email VARCHAR(150) NULL,
                address VARCHAR(300) NULL,
                pincode CHAR(6) NULL,
                area VARCHAR(100) NULL,
                city VARCHAR(80) NULL,
                industry VARCHAR(100) NULL,
                services_interested JSON NULL,
                source_id INT UNSIGNED NULL,
                campaign VARCHAR(100) NULL,
                assigned_to INT UNSIGNED NULL,
                priority ENUM('low','medium','high','hot') NOT NULL DEFAULT 'medium',
                stage_id INT UNSIGNED NOT NULL,
                expected_value DECIMAL(15,2) NULL,
                next_followup_at DATETIME NULL,
                remarks TEXT NULL,
                last_action VARCHAR(255) NULL,
                last_action_at DATETIME NULL,
                call_attempts INT UNSIGNED NOT NULL DEFAULT 0,
                connected_calls INT UNSIGNED NOT NULL DEFAULT 0,
                lost_reason_id INT UNSIGNED NULL,
                consent_status ENUM('unknown','consented','withdrawn') NOT NULL DEFAULT 'unknown',
                dnd TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'do not call',
                dnd_marked_at DATETIME NULL,
                contact_id BIGINT UNSIGNED NULL COMMENT 'set on conversion',
                converted_at DATETIME NULL,
                retention_until DATE NULL,
                {$a},
                deleted_at DATETIME NULL,
                UNIQUE KEY uq_lead_code (lead_code),
                KEY idx_lead_mobile (mobile_normalized),
                KEY idx_lead_assigned (assigned_to, next_followup_at),
                KEY idx_lead_stage (stage_id),
                KEY idx_lead_created (created_at),
                CONSTRAINT fk_lead_source FOREIGN KEY (source_id) REFERENCES lead_sources(id) ON DELETE SET NULL,
                CONSTRAINT fk_lead_user FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_lead_stage FOREIGN KEY (stage_id) REFERENCES crm_stages(id),
                CONSTRAINT fk_lead_lost FOREIGN KEY (lost_reason_id) REFERENCES lost_reasons(id) ON DELETE SET NULL,
                CONSTRAINT fk_lead_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL
            )",

            "ALTER TABLE billing_documents ADD CONSTRAINT fk_bd_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL",

            "CREATE TABLE lead_activities (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                lead_id BIGINT UNSIGNED NOT NULL,
                activity_type ENUM('created','call','stage_change','assignment','followup_set','followup_done','visit','meeting','task','note','conversion','import','edit','dnd') NOT NULL,
                title VARCHAR(255) NOT NULL,
                details TEXT NULL,
                ref_type VARCHAR(40) NULL,
                ref_id BIGINT UNSIGNED NULL,
                actor_id INT UNSIGNED NULL,
                occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_la_lead (lead_id, occurred_at),
                KEY idx_la_actor (actor_id, occurred_at),
                CONSTRAINT fk_la_lead FOREIGN KEY (lead_id) REFERENCES leads(id)
            )",

            "CREATE TABLE call_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                lead_id BIGINT UNSIGNED NOT NULL,
                called_at DATETIME NOT NULL,
                direction ENUM('outbound','inbound') NOT NULL DEFAULT 'outbound',
                person_spoken VARCHAR(150) NULL,
                duration_seconds INT UNSIGNED NULL,
                outcome ENUM('connected','interested','not_interested','call_back','no_answer','busy','switched_off','wrong_number','dnd_requested') NOT NULL,
                is_connected TINYINT(1) NOT NULL DEFAULT 0,
                notes TEXT NULL,
                next_action VARCHAR(255) NULL,
                next_action_at DATETIME NULL,
                followup_owner_id INT UNSIGNED NULL,
                caller_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_cl_lead (lead_id, called_at),
                KEY idx_cl_caller (caller_id, called_at),
                CONSTRAINT fk_cl_lead FOREIGN KEY (lead_id) REFERENCES leads(id),
                CONSTRAINT fk_cl_caller FOREIGN KEY (caller_id) REFERENCES users(id)
            )",

            "CREATE TABLE followups (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                lead_id BIGINT UNSIGNED NOT NULL,
                owner_id INT UNSIGNED NOT NULL,
                due_at DATETIME NOT NULL,
                title VARCHAR(255) NOT NULL,
                notes TEXT NULL,
                status ENUM('pending','done','cancelled') NOT NULL DEFAULT 'pending',
                source_call_id BIGINT UNSIGNED NULL,
                completed_at DATETIME NULL,
                completed_by INT UNSIGNED NULL,
                outcome_notes TEXT NULL,
                {$a},
                KEY idx_fu_owner (owner_id, status, due_at),
                KEY idx_fu_lead (lead_id),
                CONSTRAINT fk_fu_lead FOREIGN KEY (lead_id) REFERENCES leads(id),
                CONSTRAINT fk_fu_owner FOREIGN KEY (owner_id) REFERENCES users(id),
                CONSTRAINT fk_fu_call FOREIGN KEY (source_call_id) REFERENCES call_logs(id) ON DELETE SET NULL
            )",

            "CREATE TABLE visits (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                lead_id BIGINT UNSIGNED NULL,
                contact_id BIGINT UNSIGNED NULL,
                visitor_id INT UNSIGNED NOT NULL COMMENT 'who is visiting (e.g. owner)',
                scheduled_at DATETIME NOT NULL,
                location VARCHAR(200) NULL,
                address VARCHAR(300) NULL,
                purpose VARCHAR(300) NOT NULL,
                status ENUM('scheduled','completed','cancelled','rescheduled') NOT NULL DEFAULT 'scheduled',
                outcome TEXT NULL,
                client_feedback TEXT NULL,
                next_action VARCHAR(255) NULL,
                next_action_at DATETIME NULL,
                completed_at DATETIME NULL,
                {$a},
                KEY idx_visit_visitor (visitor_id, status, scheduled_at),
                KEY idx_visit_lead (lead_id),
                CONSTRAINT fk_visit_lead FOREIGN KEY (lead_id) REFERENCES leads(id),
                CONSTRAINT fk_visit_contact FOREIGN KEY (contact_id) REFERENCES contacts(id),
                CONSTRAINT fk_visit_user FOREIGN KEY (visitor_id) REFERENCES users(id)
            )",

            "CREATE TABLE meetings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                lead_id BIGINT UNSIGNED NULL,
                contact_id BIGINT UNSIGNED NULL,
                title VARCHAR(200) NOT NULL,
                meeting_at DATETIME NOT NULL,
                duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
                mode ENUM('in_person','phone','video') NOT NULL DEFAULT 'in_person',
                location_or_link VARCHAR(300) NULL,
                organizer_id INT UNSIGNED NOT NULL,
                attendee_ids JSON NULL,
                status ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
                outcome TEXT NULL,
                {$a},
                KEY idx_mtg_org (organizer_id, meeting_at),
                KEY idx_mtg_lead (lead_id),
                CONSTRAINT fk_mtg_lead FOREIGN KEY (lead_id) REFERENCES leads(id),
                CONSTRAINT fk_mtg_contact FOREIGN KEY (contact_id) REFERENCES contacts(id),
                CONSTRAINT fk_mtg_user FOREIGN KEY (organizer_id) REFERENCES users(id)
            )",

            "CREATE TABLE tasks (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                assigned_to INT UNSIGNED NOT NULL,
                assigned_by INT UNSIGNED NULL,
                due_at DATETIME NULL,
                priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
                status ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
                related_type VARCHAR(40) NULL COMMENT 'lead | visit | contact | document',
                related_id BIGINT UNSIGNED NULL,
                completed_at DATETIME NULL,
                completion_notes TEXT NULL,
                {$a},
                KEY idx_task_user (assigned_to, status, due_at),
                KEY idx_task_rel (related_type, related_id),
                CONSTRAINT fk_task_to FOREIGN KEY (assigned_to) REFERENCES users(id),
                CONSTRAINT fk_task_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
            )",

            "CREATE TABLE task_reminders (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                entity_type ENUM('task','followup','visit','meeting','interview','recurring_invoice','recurring_expense') NOT NULL,
                entity_id BIGINT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                remind_at DATETIME NOT NULL,
                channel ENUM('in_app','email') NOT NULL DEFAULT 'in_app',
                sent_at DATETIME NULL,
                cancelled_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_tr (entity_type, entity_id, user_id, remind_at, channel),
                KEY idx_tr_due (sent_at, remind_at),
                CONSTRAINT fk_tr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )",

            "CREATE TABLE lead_assignments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                lead_id BIGINT UNSIGNED NOT NULL,
                from_user_id INT UNSIGNED NULL,
                to_user_id INT UNSIGNED NULL,
                assigned_by INT UNSIGNED NULL,
                reason VARCHAR(255) NULL,
                assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_las_lead (lead_id),
                CONSTRAINT fk_las_lead FOREIGN KEY (lead_id) REFERENCES leads(id)
            )",

            "CREATE TABLE lead_conversion_links (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                lead_id BIGINT UNSIGNED NOT NULL,
                contact_id BIGINT UNSIGNED NOT NULL,
                document_id BIGINT UNSIGNED NULL,
                attributed_user_id INT UNSIGNED NULL,
                attribution JSON NULL COMMENT 'split credit: [{user_id, percent}]',
                contract_value DECIMAL(15,2) NULL,
                converted_by INT UNSIGNED NULL,
                converted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_lcl_lead (lead_id),
                CONSTRAINT fk_lcl_lead FOREIGN KEY (lead_id) REFERENCES leads(id),
                CONSTRAINT fk_lcl_contact FOREIGN KEY (contact_id) REFERENCES contacts(id),
                CONSTRAINT fk_lcl_doc FOREIGN KEY (document_id) REFERENCES billing_documents(id) ON DELETE SET NULL
            )",

            "CREATE TABLE crm_daily_targets (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                effective_from DATE NOT NULL,
                calls_per_day SMALLINT UNSIGNED NOT NULL DEFAULT 60,
                connected_per_day SMALLINT UNSIGNED NOT NULL DEFAULT 20,
                followups_per_day SMALLINT UNSIGNED NOT NULL DEFAULT 15,
                meetings_per_week SMALLINT UNSIGNED NOT NULL DEFAULT 5,
                {$a},
                UNIQUE KEY uq_cdt (user_id, effective_from),
                CONSTRAINT fk_cdt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )",

            "CREATE TABLE google_sync_settings (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                spreadsheet_id VARCHAR(120) NULL,
                credentials_path VARCHAR(255) NULL COMMENT 'service-account JSON path outside webroot; never in DB',
                worksheets JSON NOT NULL COMMENT 'logical → tab name',
                field_map JSON NOT NULL COMMENT 'per worksheet column list',
                frequency_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 5,
                direction ENUM('db_to_sheets') NOT NULL DEFAULT 'db_to_sheets' COMMENT 'two-way is future scope',
                last_success_at DATETIME NULL,
                last_error VARCHAR(1000) NULL,
                last_error_at DATETIME NULL,
                last_run_at DATETIME NULL,
                updated_by INT UNSIGNED NULL,
                updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT chk_gss_singleton CHECK (id = 1)
            )",

            "CREATE TABLE google_sync_jobs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                entity_type VARCHAR(40) NOT NULL,
                entity_id BIGINT UNSIGNED NOT NULL,
                worksheet VARCHAR(60) NOT NULL,
                action ENUM('upsert','delete') NOT NULL DEFAULT 'upsert',
                payload_hash CHAR(64) NULL,
                idempotency_key VARCHAR(160) NOT NULL,
                status ENUM('pending','processing','done','failed','dead') NOT NULL DEFAULT 'pending',
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 8,
                next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                locked_at DATETIME NULL,
                locked_by VARCHAR(64) NULL,
                last_error VARCHAR(1000) NULL,
                remote_row_ref VARCHAR(40) NULL,
                last_success_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_gsj_idem (idempotency_key),
                KEY idx_gsj_due (status, next_attempt_at),
                KEY idx_gsj_entity (entity_type, entity_id, worksheet, status)
            )",

            "CREATE TABLE google_sync_row_map (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                worksheet VARCHAR(60) NOT NULL,
                entity_type VARCHAR(40) NOT NULL,
                entity_id BIGINT UNSIGNED NOT NULL,
                sheet_row INT UNSIGNED NOT NULL,
                last_synced_hash CHAR(64) NULL,
                last_synced_at DATETIME NULL,
                UNIQUE KEY uq_gsrm (worksheet, entity_type, entity_id)
            )",

            "CREATE TABLE google_sync_runs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                started_at DATETIME NOT NULL,
                finished_at DATETIME NULL,
                trigger_source ENUM('cron','manual','test') NOT NULL DEFAULT 'cron',
                processed INT UNSIGNED NOT NULL DEFAULT 0,
                succeeded INT UNSIGNED NOT NULL DEFAULT 0,
                failed INT UNSIGNED NOT NULL DEFAULT 0,
                message VARCHAR(1000) NULL
            )",

            "CREATE TABLE cron_runs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                task VARCHAR(60) NOT NULL,
                started_at DATETIME NOT NULL,
                finished_at DATETIME NULL,
                status ENUM('running','ok','error') NOT NULL DEFAULT 'running',
                message VARCHAR(1000) NULL,
                KEY idx_cron_task (task, started_at)
            )",
        ]);
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE billing_documents DROP FOREIGN KEY fk_bd_lead');
        $this->dropTables([
            'cron_runs', 'google_sync_runs', 'google_sync_row_map', 'google_sync_jobs', 'google_sync_settings',
            'crm_daily_targets', 'lead_conversion_links', 'lead_assignments', 'task_reminders', 'tasks', 'meetings',
            'visits', 'followups', 'call_logs', 'lead_activities', 'leads', 'lost_reasons', 'lead_sources', 'crm_stages',
        ]);
    }
}
