<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Tables for the field-agent survey programme and the warehouse panel.
 *
 * Additive only. Nothing existing is dropped, renamed or altered, and every
 * table is created IF NOT EXISTS so a re-run is harmless.
 *
 * Why each exists:
 *
 *  agent_dealer_assignments  which agent covers which dealer, and how often
 *                            that dealer is surveyed. The schedule lives here
 *                            rather than on `dealers` because it is a property
 *                            of the assignment, and a dealer may be reassigned
 *                            without losing their survey history.
 *
 *  dealer_surveys            one visit. Opened when the agent starts, closed
 *                            when they submit. The counted figures are frozen
 *                            onto the row at submission so the report shows
 *                            what was true that day, not what is true now.
 *
 *  dealer_survey_items       one line per unit examined, with what the system
 *                            believed and what the agent found. The pair is
 *                            kept side by side deliberately: the comparison is
 *                            the evidence.
 *
 *  inventory_discrepancies   raised where those two disagree. A separate table
 *                            because a discrepancy outlives the survey that
 *                            found it and is worked through by someone else.
 *
 *  warranty_review_cases     an agent's RECOMMENDATION that a warranty be
 *                            rejected, and the decision an authorised person
 *                            later records against it. An agent never rejects
 *                            a customer's warranty; they open a case.
 *
 *  warranty_review_events    every note, request, upload and decision on a
 *                            case, in order. Append-only.
 *
 *  warehouse_scans           every scan, accepted or not. Makes scanning
 *                            idempotent — the unique key is what stops a
 *                            second scan of the same label counting twice —
 *                            and leaves a record of what was scanned when.
 */
class AgentSurveyAndWarehouse extends SchemaMigration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `agent_dealer_assignments` (
              `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              `agent_user_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              `dealer_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              -- How often this dealer is visited. Six months is the default
              -- the business asked for; admin may set another per dealer.
              `survey_interval_days` SMALLINT UNSIGNED NOT NULL DEFAULT 182,
              `last_surveyed_on` DATE NULL,
              `next_due_on` DATE NULL,
              `active` TINYINT(1) NOT NULL DEFAULT 1,
              `notes` VARCHAR(500) NULL,
              `assigned_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
              `created_at` DATETIME(6) NOT NULL,
              `updated_at` DATETIME(6) NULL,
              PRIMARY KEY (`id`),
              -- One live assignment per agent/dealer pair.
              UNIQUE KEY `agent_dealer_unique` (`agent_user_id`, `dealer_id`),
              KEY `agent_assignments_due_idx` (`agent_user_id`, `next_due_on`),
              KEY `agent_assignments_dealer_idx` (`dealer_id`),
              CONSTRAINT `agent_assignments_agent_fk` FOREIGN KEY (`agent_user_id`)
                REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
              CONSTRAINT `agent_assignments_dealer_fk` FOREIGN KEY (`dealer_id`)
                REFERENCES `dealers` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
              CONSTRAINT `agent_assignments_interval_chk` CHECK (`survey_interval_days` BETWEEN 7 AND 1095)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS `dealer_surveys` (
              `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              `reference` VARCHAR(24) NOT NULL,
              `dealer_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              `agent_user_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              `status` ENUM('IN_PROGRESS', 'SUBMITTED', 'REVIEWED', 'ABANDONED') NOT NULL DEFAULT 'IN_PROGRESS',
              `started_at` DATETIME(6) NOT NULL,
              `submitted_at` DATETIME(6) NULL,
              `reviewed_at` DATETIME(6) NULL,
              `reviewed_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
              -- Frozen at submission: the report must show the position on the
              -- day of the visit, not a figure that drifts afterwards.
              `system_total` INT UNSIGNED NOT NULL DEFAULT 0,
              `system_available` INT UNSIGNED NOT NULL DEFAULT 0,
              `system_sold` INT UNSIGNED NOT NULL DEFAULT 0,
              `physically_found` INT UNSIGNED NOT NULL DEFAULT 0,
              `reported_sold` INT UNSIGNED NOT NULL DEFAULT 0,
              `not_found` INT UNSIGNED NOT NULL DEFAULT 0,
              `damaged` INT UNSIGNED NOT NULL DEFAULT 0,
              `discrepancy_count` INT UNSIGNED NOT NULL DEFAULT 0,
              `agent_remarks` TEXT NULL,
              `created_at` DATETIME(6) NOT NULL,
              `updated_at` DATETIME(6) NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `dealer_surveys_reference_key` (`reference`),
              KEY `dealer_surveys_dealer_idx` (`dealer_id`, `started_at`),
              KEY `dealer_surveys_agent_idx` (`agent_user_id`, `status`),
              CONSTRAINT `dealer_surveys_dealer_fk` FOREIGN KEY (`dealer_id`)
                REFERENCES `dealers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `dealer_surveys_agent_fk` FOREIGN KEY (`agent_user_id`)
                REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `dealer_surveys_submitted_chk`
                CHECK (`status` <> 'SUBMITTED' OR `submitted_at` IS NOT NULL)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS `dealer_survey_items` (
              `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              `survey_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              `mattress_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              -- What the system believed at the moment of the check.
              `system_status` VARCHAR(32) NOT NULL,
              -- What the agent actually found.
              `observation` ENUM('PRESENT', 'DEALER_SAYS_SOLD', 'NOT_FOUND', 'DAMAGED', 'OTHER') NOT NULL,
              -- The verdict of comparing the two. Computed, never typed.
              `outcome` ENUM('MATCHED', 'POSSIBLE_UNRECORDED_SALE', 'REVIEW_REQUIRED', 'STOCK_DISCREPANCY', 'DAMAGED') NOT NULL,
              `scanned` TINYINT(1) NOT NULL DEFAULT 0,
              `remark` VARCHAR(500) NULL,
              `recorded_at` DATETIME(6) NOT NULL,
              PRIMARY KEY (`id`),
              -- A unit is examined once per survey. Scanning the same label
              -- twice updates the line rather than adding another.
              UNIQUE KEY `survey_mattress_unique` (`survey_id`, `mattress_id`),
              KEY `survey_items_outcome_idx` (`survey_id`, `outcome`),
              KEY `survey_items_mattress_idx` (`mattress_id`),
              CONSTRAINT `survey_items_survey_fk` FOREIGN KEY (`survey_id`)
                REFERENCES `dealer_surveys` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
              CONSTRAINT `survey_items_mattress_fk` FOREIGN KEY (`mattress_id`)
                REFERENCES `mattresses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS `inventory_discrepancies` (
              `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              `survey_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
              `survey_item_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
              `dealer_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              `mattress_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              `kind` ENUM('POSSIBLE_UNRECORDED_SALE', 'REVIEW_REQUIRED', 'STOCK_DISCREPANCY', 'DAMAGED', 'OTHER') NOT NULL,
              `system_status` VARCHAR(32) NOT NULL,
              `observation` VARCHAR(32) NOT NULL,
              `status` ENUM('OPEN', 'UNDER_REVIEW', 'RESOLVED', 'DISMISSED') NOT NULL DEFAULT 'OPEN',
              `raised_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              `raised_at` DATETIME(6) NOT NULL,
              `resolved_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
              `resolved_at` DATETIME(6) NULL,
              `resolution_note` VARCHAR(1000) NULL,
              `agent_remark` VARCHAR(500) NULL,
              PRIMARY KEY (`id`),
              KEY `discrepancies_status_idx` (`status`, `raised_at`),
              KEY `discrepancies_dealer_idx` (`dealer_id`, `status`),
              KEY `discrepancies_mattress_idx` (`mattress_id`),
              CONSTRAINT `discrepancies_dealer_fk` FOREIGN KEY (`dealer_id`)
                REFERENCES `dealers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `discrepancies_mattress_fk` FOREIGN KEY (`mattress_id`)
                REFERENCES `mattresses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `discrepancies_survey_fk` FOREIGN KEY (`survey_id`)
                REFERENCES `dealer_surveys` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
              CONSTRAINT `discrepancies_resolved_chk`
                CHECK (`status` NOT IN ('RESOLVED', 'DISMISSED') OR `resolved_at` IS NOT NULL)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS `warranty_review_cases` (
              `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              `reference` VARCHAR(24) NOT NULL,
              `mattress_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              `warranty_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
              `dealer_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              `survey_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
              `raised_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              `raised_at` DATETIME(6) NOT NULL,
              `reason` ENUM('INVOICE_NOT_UPLOADED', 'SALE_NOT_REGISTERED', 'INVOICE_MISMATCH',
                            'INCORRECT_SALE_INFORMATION', 'OTHER') NOT NULL,
              `agent_remark` VARCHAR(1000) NULL,
              -- RECOMMENDED is where an agent leaves it. Only an authorised
              -- reviewer moves it on; an agent cannot reject a warranty.
              `status` ENUM('RECOMMENDED', 'UNDER_REVIEW', 'INFORMATION_REQUESTED',
                            'UPHELD', 'DISMISSED', 'WITHDRAWN') NOT NULL DEFAULT 'RECOMMENDED',
              `decided_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
              `decided_at` DATETIME(6) NULL,
              `decision_reason` VARCHAR(1000) NULL,
              `dealer_visible_note` VARCHAR(1000) NULL,
              `invoice_media_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
              `created_at` DATETIME(6) NOT NULL,
              `updated_at` DATETIME(6) NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `warranty_review_reference_key` (`reference`),
              KEY `warranty_review_status_idx` (`status`, `raised_at`),
              KEY `warranty_review_dealer_idx` (`dealer_id`, `status`),
              KEY `warranty_review_mattress_idx` (`mattress_id`),
              CONSTRAINT `warranty_review_mattress_fk` FOREIGN KEY (`mattress_id`)
                REFERENCES `mattresses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `warranty_review_dealer_fk` FOREIGN KEY (`dealer_id`)
                REFERENCES `dealers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `warranty_review_survey_fk` FOREIGN KEY (`survey_id`)
                REFERENCES `dealer_surveys` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
              -- A decision needs a decider, a time and a reason. All three.
              CONSTRAINT `warranty_review_decision_chk`
                CHECK (`status` NOT IN ('UPHELD', 'DISMISSED')
                       OR (`decided_by` IS NOT NULL AND `decided_at` IS NOT NULL AND `decision_reason` IS NOT NULL))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS `warranty_review_events` (
              `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              `case_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              `actor_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
              `actor_role` VARCHAR(32) NULL,
              `event` VARCHAR(48) NOT NULL,
              `from_status` VARCHAR(32) NULL,
              `to_status` VARCHAR(32) NULL,
              `note` TEXT NULL,
              `dealer_visible` TINYINT(1) NOT NULL DEFAULT 0,
              `occurred_at` DATETIME(6) NOT NULL,
              PRIMARY KEY (`id`),
              KEY `warranty_review_events_case_idx` (`case_id`, `occurred_at`),
              CONSTRAINT `warranty_review_events_case_fk` FOREIGN KEY (`case_id`)
                REFERENCES `warranty_review_cases` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS `warehouse_scans` (
              `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              `mattress_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              `batch_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
              `dispatch_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
              -- PRODUCTION counts a unit as made; DISPATCH stages it for a
              -- consignment. The purpose is part of the unique key, so the
              -- same unit can be scanned once for each without clashing.
              `purpose` ENUM('PRODUCTION', 'DISPATCH') NOT NULL,
              `scanned_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              `scanned_at` DATETIME(6) NOT NULL,
              `source` ENUM('CAMERA', 'MANUAL') NOT NULL DEFAULT 'CAMERA',
              PRIMARY KEY (`id`),
              -- This is what makes scanning idempotent. A second scan of the
              -- same label for the same purpose and context is rejected by
              -- the database, not merely by the application.
              UNIQUE KEY `warehouse_scan_unique` (`mattress_id`, `purpose`, `batch_id`, `dispatch_id`),
              KEY `warehouse_scans_batch_idx` (`batch_id`, `scanned_at`),
              KEY `warehouse_scans_dispatch_idx` (`dispatch_id`, `scanned_at`),
              CONSTRAINT `warehouse_scans_mattress_fk` FOREIGN KEY (`mattress_id`)
                REFERENCES `mattresses` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
              CONSTRAINT `warehouse_scans_user_fk` FOREIGN KEY (`scanned_by`)
                REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `warehouse_scans_context_chk`
                CHECK ((`purpose` = 'PRODUCTION' AND `batch_id` IS NOT NULL)
                    OR (`purpose` = 'DISPATCH' AND `dispatch_id` IS NOT NULL))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        ");
    }

    public function down(): void
    {
        // Children first.
        foreach ([
            'warehouse_scans',
            'warranty_review_events',
            'warranty_review_cases',
            'inventory_discrepancies',
            'dealer_survey_items',
            'dealer_surveys',
            'agent_dealer_assignments',
        ] as $table) {
            $this->db->query("DROP TABLE IF EXISTS `{$table}`");
        }
    }
}
