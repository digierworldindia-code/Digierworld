<?php

/**
 * The POLYFIX MATTRESS core schema, translated from the PostgreSQL database the
 * platform previously ran on. Generated from that database's catalog so column
 * names, types, keys, indexes and constraints match it exactly; see
 * docs/migration.md for every translation decision.
 *
 * Returned as an ordered list: every table first, then every foreign key, so
 * no table has to be created before the ones it references.
 *
 * @return array{tables: array<string,string>, foreignKeys: list<string>}
 */
return [
    'tables' => [
        'legacy_prisma_migrations' => <<<'SQL'
CREATE TABLE `legacy_prisma_migrations` (
  `id` VARCHAR(36) NOT NULL,
  `checksum` VARCHAR(64) NOT NULL,
  `finished_at` DATETIME(6) NULL,
  `migration_name` VARCHAR(255) NOT NULL,
  `logs` TEXT NULL,
  `rolled_back_at` DATETIME(6) NULL,
  `started_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `applied_steps_count` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'audit_logs' => <<<'SQL'
CREATE TABLE `audit_logs` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `occurred_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `user_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `user_email` VARCHAR(255) NULL,
  `role_key` VARCHAR(40) NULL,
  `dealer_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `action` VARCHAR(60) NOT NULL,
  `entity` VARCHAR(60) NOT NULL,
  `entity_id` VARCHAR(64) NULL,
  `ip` VARCHAR(64) NULL,
  `user_agent` VARCHAR(400) NULL,
  `previous_value` LONGTEXT NULL,
  `new_value` LONGTEXT NULL,
  `reason` TEXT NULL,
  `request_id` VARCHAR(64) NULL,
  `prev_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `row_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `audit_logs_action_idx` (`action`),
  KEY `audit_logs_entity_entity_id_idx` (`entity`, `entity_id`),
  KEY `audit_logs_occurred_at_idx` (`occurred_at`),
  KEY `audit_logs_user_id_occurred_at_idx` (`user_id`, `occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'backup_runs' => <<<'SQL'
CREATE TABLE `backup_runs` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `kind` ENUM('DAILY', 'WEEKLY', 'MONTHLY', 'MANUAL', 'EXPORT') NOT NULL,
  `status` ENUM('RUNNING', 'SUCCESS', 'FAILED') NOT NULL DEFAULT 'RUNNING',
  `started_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `finished_at` DATETIME(6) NULL,
  `artifact_path` VARCHAR(400) NULL,
  `byte_size` BIGINT NULL,
  `checksum` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `encrypted` TINYINT(1) NOT NULL DEFAULT 1,
  `offsite_copied` TINYINT(1) NOT NULL DEFAULT 0,
  `requested_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `error` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `backup_runs_kind_started_at_idx` (`kind`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'brand_rename_backup' => <<<'SQL'
CREATE TABLE `brand_rename_backup` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `migration` TEXT NOT NULL DEFAULT ('0006_brand_rename_polyfix'),
  `table_name` VARCHAR(191) NOT NULL,
  `record_id` VARCHAR(191) NOT NULL,
  `column_name` VARCHAR(191) NOT NULL,
  `old_value` TEXT NULL,
  `captured_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `brand_rename_backup_lookup_idx` (`table_name`, `record_id`, `column_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'claim_events' => <<<'SQL'
CREATE TABLE `claim_events` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `claim_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_type` VARCHAR(40) NOT NULL,
  `from_status` ENUM('SUBMITTED', 'UNDER_REVIEW', 'INFO_REQUESTED', 'APPROVED', 'REJECTED', 'REPLACED', 'CLOSED', 'WITHDRAWN') NULL,
  `to_status` ENUM('SUBMITTED', 'UNDER_REVIEW', 'INFO_REQUESTED', 'APPROVED', 'REJECTED', 'REPLACED', 'CLOSED', 'WITHDRAWN') NULL,
  `actor_user_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `note` TEXT NULL,
  `is_internal` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `claim_events_claim_id_created_at_idx` (`claim_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'claim_media' => <<<'SQL'
CREATE TABLE `claim_media` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `claim_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `dealer_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `kind` ENUM('CLAIM_PHOTO', 'CLAIM_INVOICE', 'PRODUCT_IMAGE', 'OG_IMAGE') NOT NULL DEFAULT 'CLAIM_PHOTO',
  `storage_key` VARCHAR(300) NOT NULL,
  `mime_type` VARCHAR(80) NOT NULL,
  `byte_size` INT NOT NULL,
  `width` INT NULL,
  `height` INT NULL,
  `sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `caption` VARCHAR(200) NULL,
  `uploaded_by_user_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `claim_media_claim_id_idx` (`claim_id`),
  KEY `claim_media_sha256_idx` (`sha256`),
  UNIQUE KEY `claim_media_storage_key_key` (`storage_key`),
  CONSTRAINT `claim_media_mime_chk` CHECK (`mime_type` IN ('image/jpeg','image/png','image/webp','application/pdf')),
  CONSTRAINT `claim_media_size_chk` CHECK (`byte_size` > 0 AND `byte_size` <= 26214400)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'contact_submissions' => <<<'SQL'
CREATE TABLE `contact_submissions` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `email` VARCHAR(255) NULL,
  `city` VARCHAR(80) NULL,
  `requirement` VARCHAR(60) NOT NULL,
  `message` TEXT NOT NULL,
  `status` ENUM('NEW', 'CONTACTED', 'FOLLOW_UP', 'CONVERTED', 'CLOSED') NOT NULL DEFAULT 'NEW',
  `source` VARCHAR(60) NOT NULL DEFAULT 'website',
  `ip_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `user_agent` VARCHAR(400) NULL,
  `spam_score` INT NOT NULL DEFAULT 0,
  `assigned_to_user_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `internal_notes` TEXT NULL,
  `responded_at` DATETIME(6) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `contact_submissions_status_created_at_idx` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'customers' => <<<'SQL'
CREATE TABLE `customers` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `dealer_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `full_name` VARCHAR(160) NOT NULL,
  `phone_encrypted` TEXT NOT NULL,
  `phone_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `email_encrypted` TEXT NULL,
  `email_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `address_line` VARCHAR(300) NULL,
  `address_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `city` VARCHAR(80) NULL,
  `state` VARCHAR(80) NULL,
  `pincode` VARCHAR(10) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `created_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `updated_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `deleted_at` DATETIME(6) NULL,
  `deleted_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `delete_reason` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `customers_dealer_id_idx` (`dealer_id`),
  UNIQUE KEY `customers_dealer_id_phone_hash_key` (`dealer_id`, `phone_hash`),
  KEY `customers_phone_hash_idx` (`phone_hash`),
  CONSTRAINT `customers_delete_reason_chk` CHECK (`deleted_at` IS NULL OR `delete_reason` IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'dealer_applications' => <<<'SQL'
CREATE TABLE `dealer_applications` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `business_name` VARCHAR(180) NOT NULL,
  `owner_name` VARCHAR(160) NOT NULL,
  `mobile` VARCHAR(20) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `city` VARCHAR(80) NOT NULL,
  `state` VARCHAR(80) NOT NULL,
  `address` VARCHAR(400) NOT NULL,
  `gst_number` VARCHAR(20) NULL,
  `has_existing_business` TINYINT(1) NOT NULL DEFAULT 0,
  `existing_business_details` TEXT NULL,
  `message` TEXT NULL,
  `status` ENUM('NEW', 'INFO_REQUESTED', 'APPROVED', 'REJECTED') NOT NULL DEFAULT 'NEW',
  `review_notes` TEXT NULL,
  `reviewed_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `reviewed_at` DATETIME(6) NULL,
  `created_dealer_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `ip_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `user_agent` VARCHAR(400) NULL,
  `spam_score` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `dealer_applications_created_dealer_id_key` (`created_dealer_id`),
  KEY `dealer_applications_status_created_at_idx` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'dealer_receipt_items' => <<<'SQL'
CREATE TABLE `dealer_receipt_items` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `receipt_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `mattress_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `condition` ENUM('OK', 'DAMAGED', 'MISSING') NOT NULL DEFAULT 'OK',
  `remarks` VARCHAR(300) NULL,
  PRIMARY KEY (`id`),
  KEY `dealer_receipt_items_mattress_id_idx` (`mattress_id`),
  UNIQUE KEY `dealer_receipt_items_receipt_id_mattress_id_key` (`receipt_id`, `mattress_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'dealer_receipts' => <<<'SQL'
CREATE TABLE `dealer_receipts` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `dispatch_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `dealer_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `received_by_user_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `received_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `remarks` TEXT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `dealer_receipts_dealer_id_received_at_idx` (`dealer_id`, `received_at`),
  KEY `dealer_receipts_dispatch_id_idx` (`dispatch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'dealer_users' => <<<'SQL'
CREATE TABLE `dealer_users` (
  `user_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `dealer_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`user_id`),
  KEY `dealer_users_dealer_id_idx` (`dealer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'dealers' => <<<'SQL'
CREATE TABLE `dealers` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `code` VARCHAR(20) NOT NULL,
  `business_name` VARCHAR(180) NOT NULL,
  `owner_name` VARCHAR(160) NOT NULL,
  `email` VARCHAR(255) NULL,
  `phone` VARCHAR(20) NOT NULL,
  `gst_number` VARCHAR(20) NULL,
  `address_line1` VARCHAR(200) NOT NULL,
  `address_line2` VARCHAR(200) NULL,
  `city` VARCHAR(80) NOT NULL,
  `state` VARCHAR(80) NOT NULL,
  `pincode` VARCHAR(10) NOT NULL,
  `latitude` DECIMAL(9,6) NULL,
  `longitude` DECIMAL(9,6) NULL,
  `status` ENUM('PENDING', 'ACTIVE', 'SUSPENDED', 'TERMINATED') NOT NULL DEFAULT 'PENDING',
  `public_listed` TINYINT(1) NOT NULL DEFAULT 0,
  `is_showroom` TINYINT(1) NOT NULL DEFAULT 1,
  `onboarded_at` DATETIME(6) NULL,
  `notes` TEXT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `created_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `updated_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `deleted_at` DATETIME(6) NULL,
  `deleted_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `delete_reason` TEXT NULL,
  PRIMARY KEY (`id`),
  FULLTEXT KEY `dealers_business_name_trgm_idx` (`business_name`),
  UNIQUE KEY `dealers_code_key` (`code`),
  KEY `dealers_public_listed_status_idx` (`public_listed`, `status`),
  KEY `dealers_state_city_idx` (`state`, `city`),
  KEY `dealers_status_idx` (`status`),
  CONSTRAINT `dealers_delete_reason_chk` CHECK (`deleted_at` IS NULL OR `delete_reason` IS NOT NULL),
  CONSTRAINT `dealers_gst_chk` CHECK (`gst_number` IS NULL OR REGEXP_LIKE(`gst_number`, '^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]{3}$', 'c')),
  CONSTRAINT `dealers_phone_chk` CHECK (REGEXP_LIKE(`phone`, '^[0-9+][0-9 -]{7,19}$', 'c')),
  CONSTRAINT `dealers_pincode_chk` CHECK (REGEXP_LIKE(`pincode`, '^[0-9]{6}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'dispatch_items' => <<<'SQL'
CREATE TABLE `dispatch_items` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `dispatch_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `mattress_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `added_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `dispatch_items_dispatch_id_mattress_id_key` (`dispatch_id`, `mattress_id`),
  KEY `dispatch_items_mattress_id_idx` (`mattress_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'dispatches' => <<<'SQL'
CREATE TABLE `dispatches` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `dispatch_code` VARCHAR(30) NOT NULL,
  `warehouse_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `dealer_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` ENUM('DRAFT', 'DISPATCHED', 'PARTIALLY_RECEIVED', 'RECEIVED', 'CANCELLED') NOT NULL DEFAULT 'DRAFT',
  `dispatched_at` DATETIME(6) NULL,
  `expected_at` DATETIME(6) NULL,
  `transporter` VARCHAR(120) NULL,
  `lr_number` VARCHAR(60) NULL,
  `vehicle_number` VARCHAR(30) NULL,
  `remarks` TEXT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `created_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `updated_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `deleted_at` DATETIME(6) NULL,
  `deleted_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `delete_reason` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `dispatches_dealer_id_status_idx` (`dealer_id`, `status`),
  UNIQUE KEY `dispatches_dispatch_code_key` (`dispatch_code`),
  KEY `dispatches_status_dispatched_at_idx` (`status`, `dispatched_at`),
  CONSTRAINT `dispatches_delete_reason_chk` CHECK (`deleted_at` IS NULL OR `delete_reason` IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'failed_login_attempts' => <<<'SQL'
CREATE TABLE `failed_login_attempts` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(255) NOT NULL,
  `ip_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_agent` VARCHAR(400) NULL,
  `reason` VARCHAR(60) NOT NULL,
  `attempted_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `failed_login_attempts_email_attempted_at_idx` (`email`, `attempted_at`),
  KEY `failed_login_attempts_ip_hash_attempted_at_idx` (`ip_hash`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'faqs' => <<<'SQL'
CREATE TABLE `faqs` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `question` VARCHAR(300) NOT NULL,
  `answer` TEXT NOT NULL,
  `category` VARCHAR(60) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 100,
  `is_published` TINYINT(1) NOT NULL DEFAULT 1,
  `product_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `page_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `updated_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  PRIMARY KEY (`id`),
  KEY `faqs_is_published_category_sort_order_idx` (`is_published`, `category`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'manufacturing_batches' => <<<'SQL'
CREATE TABLE `manufacturing_batches` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `batch_code` VARCHAR(30) NOT NULL,
  `warehouse_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `manufactured_on` DATE NOT NULL,
  `planned_quantity` INT NOT NULL,
  `produced_quantity` INT NOT NULL DEFAULT 0,
  `line_supervisor` VARCHAR(120) NULL,
  `quality_checked_by` VARCHAR(120) NULL,
  `notes` TEXT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `created_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `manufacturing_batches_batch_code_key` (`batch_code`),
  KEY `manufacturing_batches_manufactured_on_idx` (`manufactured_on`),
  CONSTRAINT `batches_quantity_chk` CHECK (`planned_quantity` > 0 AND `produced_quantity` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'mattress_events' => <<<'SQL'
CREATE TABLE `mattress_events` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `mattress_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_type` VARCHAR(40) NOT NULL,
  `from_status` ENUM('MANUFACTURED', 'IN_DISPATCH', 'DISPATCHED', 'DEALER_RECEIVED', 'SOLD', 'CLAIM_OPEN', 'REPLACED', 'RETURNED', 'SCRAPPED') NULL,
  `to_status` ENUM('MANUFACTURED', 'IN_DISPATCH', 'DISPATCHED', 'DEALER_RECEIVED', 'SOLD', 'CLAIM_OPEN', 'REPLACED', 'RETURNED', 'SCRAPPED') NULL,
  `actor_user_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `dealer_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `payload` JSON NULL,
  `occurred_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `mattress_events_event_type_idx` (`event_type`),
  KEY `mattress_events_mattress_id_occurred_at_idx` (`mattress_id`, `occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'mattresses' => <<<'SQL'
CREATE TABLE `mattresses` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `serial_number` VARCHAR(20) NOT NULL,
  `qr_token` VARCHAR(43) NOT NULL,
  `product_variant_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `batch_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `current_status` ENUM('MANUFACTURED', 'IN_DISPATCH', 'DISPATCHED', 'DEALER_RECEIVED', 'SOLD', 'CLAIM_OPEN', 'REPLACED', 'RETURNED', 'SCRAPPED') NOT NULL DEFAULT 'MANUFACTURED',
  `current_dealer_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `current_warehouse_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `manufactured_at` DATETIME(6) NOT NULL,
  `dispatched_at` DATETIME(6) NULL,
  `received_at` DATETIME(6) NULL,
  `sold_at` DATETIME(6) NULL,
  `is_replacement` TINYINT(1) NOT NULL DEFAULT 0,
  `replacement_for_claim_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `grade_note` VARCHAR(200) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `created_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `updated_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `deleted_at` DATETIME(6) NULL,
  `deleted_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `delete_reason` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `mattresses_batch_id_idx` (`batch_id`),
  KEY `mattresses_current_dealer_id_current_status_idx` (`current_dealer_id`, `current_status`),
  KEY `mattresses_current_status_idx` (`current_status`),
  KEY `mattresses_manufactured_at_idx` (`manufactured_at`),
  KEY `mattresses_product_variant_id_idx` (`product_variant_id`),
  UNIQUE KEY `mattresses_qr_token_key` (`qr_token`),
  UNIQUE KEY `mattresses_replacement_for_claim_id_key` (`replacement_for_claim_id`),
  UNIQUE KEY `mattresses_serial_number_key` (`serial_number`),
  FULLTEXT KEY `mattresses_serial_trgm_idx` (`serial_number`),
  CONSTRAINT `mattresses_custody_chk` CHECK (`current_status` NOT IN ('DEALER_RECEIVED','SOLD','CLAIM_OPEN','REPLACED') OR `current_dealer_id` IS NOT NULL),
  CONSTRAINT `mattresses_delete_reason_chk` CHECK (`deleted_at` IS NULL OR `delete_reason` IS NOT NULL),
  CONSTRAINT `mattresses_qr_token_format_chk` CHECK (CHAR_LENGTH(`qr_token`) >= 22),
  CONSTRAINT `mattresses_serial_format_chk` CHECK (REGEXP_LIKE(`serial_number`, '^CLF[0-9]{8,}$', 'c')),
  CONSTRAINT `mattresses_sold_at_chk` CHECK (`current_status` NOT IN ('SOLD','CLAIM_OPEN','REPLACED') OR `sold_at` IS NOT NULL),
  CONSTRAINT `mattresses_timeline_chk` CHECK ((`dispatched_at` IS NULL OR DATE(`dispatched_at`) >= DATE(`manufactured_at`)) AND (`received_at` IS NULL OR `dispatched_at` IS NULL OR DATE(`received_at`) >= DATE(`dispatched_at`)) AND (`sold_at` IS NULL OR `received_at` IS NULL OR DATE(`sold_at`) >= DATE(`received_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'notifications' => <<<'SQL'
CREATE TABLE `notifications` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `dealer_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `channel` ENUM('IN_APP', 'EMAIL', 'WHATSAPP', 'SMS') NOT NULL DEFAULT 'IN_APP',
  `type` VARCHAR(60) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `body` TEXT NOT NULL,
  `entity` VARCHAR(60) NULL,
  `entity_id` VARCHAR(64) NULL,
  `read_at` DATETIME(6) NULL,
  `sent_at` DATETIME(6) NULL,
  `error` TEXT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `notifications_dealer_id_idx` (`dealer_id`),
  KEY `notifications_user_id_read_at_idx` (`user_id`, `read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'password_reset_tokens' => <<<'SQL'
CREATE TABLE `password_reset_tokens` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expires_at` DATETIME(6) NOT NULL,
  `used_at` DATETIME(6) NULL,
  `created_ip` VARCHAR(64) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `password_reset_tokens_expires_at_idx` (`expires_at`),
  UNIQUE KEY `password_reset_tokens_token_hash_key` (`token_hash`),
  KEY `password_reset_tokens_user_id_idx` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'permissions' => <<<'SQL'
CREATE TABLE `permissions` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `key` VARCHAR(80) NOT NULL,
  `group` VARCHAR(40) NOT NULL,
  `description` TEXT NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `permissions_group_idx` (`group`),
  UNIQUE KEY `permissions_key_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'product_variants' => <<<'SQL'
CREATE TABLE `product_variants` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `product_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `sku` VARCHAR(40) NOT NULL,
  `size_label` VARCHAR(60) NOT NULL,
  `width_in` INT NOT NULL,
  `length_in` INT NOT NULL,
  `height_in` INT NOT NULL,
  `mrp` DECIMAL(12,2) NOT NULL,
  `weight_kg` DECIMAL(6,2) NULL,
  `status` ENUM('DRAFT', 'PUBLISHED', 'ARCHIVED') NOT NULL DEFAULT 'PUBLISHED',
  `sort_order` INT NOT NULL DEFAULT 100,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `deleted_at` DATETIME(6) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_variants_product_id_size_label_key` (`product_id`, `size_label`),
  KEY `product_variants_product_id_status_idx` (`product_id`, `status`),
  UNIQUE KEY `product_variants_sku_key` (`sku`),
  CONSTRAINT `product_variants_dimensions_chk` CHECK (`width_in` > 0 AND `length_in` > 0 AND `height_in` > 0),
  CONSTRAINT `product_variants_mrp_chk` CHECK (`mrp` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'products' => <<<'SQL'
CREATE TABLE `products` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `slug` VARCHAR(120) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `sku_prefix` VARCHAR(12) NOT NULL,
  `category` VARCHAR(60) NOT NULL,
  `tagline` VARCHAR(200) NULL,
  `short_description` VARCHAR(400) NOT NULL,
  `description` TEXT NOT NULL,
  `comfort_level` VARCHAR(40) NOT NULL,
  `firmness_score` INT NOT NULL,
  `materials` JSON NOT NULL DEFAULT (JSON_ARRAY()),
  `features` JSON NOT NULL DEFAULT (JSON_ARRAY()),
  `specifications` JSON NOT NULL DEFAULT (JSON_OBJECT()),
  `care_instructions` TEXT NULL,
  `warranty_years` INT NOT NULL,
  `trial_nights` INT NULL,
  `status` ENUM('DRAFT', 'PUBLISHED', 'ARCHIVED') NOT NULL DEFAULT 'DRAFT',
  `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 100,
  `published_at` DATETIME(6) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `created_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `updated_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `deleted_at` DATETIME(6) NULL,
  `deleted_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `delete_reason` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `products_category_idx` (`category`),
  FULLTEXT KEY `products_name_trgm_idx` (`name`),
  UNIQUE KEY `products_sku_prefix_key` (`sku_prefix`),
  UNIQUE KEY `products_slug_key` (`slug`),
  KEY `products_status_is_featured_idx` (`status`, `is_featured`),
  CONSTRAINT `products_delete_reason_chk` CHECK (`deleted_at` IS NULL OR `delete_reason` IS NOT NULL),
  CONSTRAINT `products_firmness_chk` CHECK (`firmness_score` BETWEEN 1 AND 10),
  CONSTRAINT `products_warranty_years_chk` CHECK (`warranty_years` BETWEEN 1 AND 30)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'record_versions' => <<<'SQL'
CREATE TABLE `record_versions` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `entity` VARCHAR(60) NOT NULL,
  `entity_id` VARCHAR(64) NOT NULL,
  `version` INT NOT NULL,
  `data` JSON NOT NULL,
  `changed_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `changed_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `reason` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `record_versions_entity_entity_id_idx` (`entity`, `entity_id`),
  UNIQUE KEY `record_versions_entity_entity_id_version_key` (`entity`, `entity_id`, `version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'refresh_tokens' => <<<'SQL'
CREATE TABLE `refresh_tokens` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `session_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `issued_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `expires_at` DATETIME(6) NOT NULL,
  `used_at` DATETIME(6) NULL,
  `revoked_at` DATETIME(6) NULL,
  `replaced_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  PRIMARY KEY (`id`),
  KEY `refresh_tokens_expires_at_idx` (`expires_at`),
  UNIQUE KEY `refresh_tokens_replaced_by_key` (`replaced_by`),
  KEY `refresh_tokens_session_id_idx` (`session_id`),
  UNIQUE KEY `refresh_tokens_token_hash_key` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'replacements' => <<<'SQL'
CREATE TABLE `replacements` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `claim_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `original_mattress_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `replacement_mattress_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `dealer_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `approved_by_user_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `issued_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `dispatch_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `remarks` TEXT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `replacements_claim_id_key` (`claim_id`),
  KEY `replacements_dealer_id_idx` (`dealer_id`),
  UNIQUE KEY `replacements_original_mattress_id_key` (`original_mattress_id`),
  UNIQUE KEY `replacements_replacement_mattress_id_key` (`replacement_mattress_id`),
  CONSTRAINT `replacements_distinct_mattress_chk` CHECK (`original_mattress_id` <> `replacement_mattress_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'role_permissions' => <<<'SQL'
CREATE TABLE `role_permissions` (
  `role_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `permission_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`role_id`, `permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'roles' => <<<'SQL'
CREATE TABLE `roles` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `key` ENUM('SUPER_ADMIN', 'ADMIN', 'WAREHOUSE', 'WARRANTY_MANAGER', 'SALES_MANAGER', 'DEALER', 'REPORTING') NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `description` TEXT NULL,
  `is_system` TINYINT(1) NOT NULL DEFAULT 1,
  `rank` INT NOT NULL DEFAULT 100,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_key_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'sales' => <<<'SQL'
CREATE TABLE `sales` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `dealer_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `mattress_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `customer_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `invoice_number` VARCHAR(60) NOT NULL,
  `sold_at` DATETIME(6) NOT NULL,
  `sale_price` DECIMAL(12,2) NOT NULL,
  `payment_mode` VARCHAR(30) NOT NULL,
  `sold_by_user_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `remarks` TEXT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `created_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `updated_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `deleted_at` DATETIME(6) NULL,
  `deleted_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `delete_reason` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `sales_customer_id_idx` (`customer_id`),
  UNIQUE KEY `sales_dealer_id_invoice_number_key` (`dealer_id`, `invoice_number`),
  KEY `sales_dealer_id_sold_at_idx` (`dealer_id`, `sold_at`),
  UNIQUE KEY `sales_mattress_id_key` (`mattress_id`),
  CONSTRAINT `sales_delete_reason_chk` CHECK (`deleted_at` IS NULL OR `delete_reason` IS NOT NULL),
  CONSTRAINT `sales_price_chk` CHECK (`sale_price` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'seo_metadata' => <<<'SQL'
CREATE TABLE `seo_metadata` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `scope` ENUM('GLOBAL', 'PAGE', 'PRODUCT') NOT NULL,
  `entity_key` VARCHAR(160) NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` VARCHAR(320) NOT NULL,
  `canonical_path` VARCHAR(300) NULL,
  `og_title` VARCHAR(200) NULL,
  `og_description` VARCHAR(320) NULL,
  `og_image_url` VARCHAR(400) NULL,
  `twitter_card` VARCHAR(40) NOT NULL DEFAULT 'summary_large_image',
  `robots_index` TINYINT(1) NOT NULL DEFAULT 1,
  `robots_follow` TINYINT(1) NOT NULL DEFAULT 1,
  `keywords` JSON NULL DEFAULT (JSON_ARRAY()),
  `sitemap_include` TINYINT(1) NOT NULL DEFAULT 1,
  `sitemap_priority` DECIMAL(2,1) NOT NULL DEFAULT 0.5,
  `sitemap_changefreq` VARCHAR(20) NOT NULL DEFAULT 'monthly',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `updated_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `seo_metadata_scope_entity_key_key` (`scope`, `entity_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'sessions' => <<<'SQL'
CREATE TABLE `sessions` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ip` VARCHAR(64) NULL,
  `user_agent` VARCHAR(400) NULL,
  `mfa_satisfied` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `last_seen_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `absolute_expiry` DATETIME(6) NOT NULL,
  `revoked_at` DATETIME(6) NULL,
  `revoked_reason` VARCHAR(120) NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_absolute_expiry_idx` (`absolute_expiry`),
  KEY `sessions_active_idx` (`user_id`, `absolute_expiry`),
  UNIQUE KEY `sessions_token_hash_key` (`token_hash`),
  KEY `sessions_user_id_idx` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'system_settings' => <<<'SQL'
CREATE TABLE `system_settings` (
  `key` VARCHAR(80) NOT NULL,
  `value` JSON NOT NULL,
  `description` TEXT NULL,
  `category` VARCHAR(40) NOT NULL DEFAULT 'general',
  `is_secret` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `updated_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'user_roles' => <<<'SQL'
CREATE TABLE `user_roles` (
  `user_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `role_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `assigned_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `assigned_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  PRIMARY KEY (`user_id`, `role_id`),
  KEY `user_roles_role_id_idx` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'users' => <<<'SQL'
CREATE TABLE `users` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `password_hash` TEXT NOT NULL,
  `full_name` VARCHAR(160) NOT NULL,
  `phone` VARCHAR(20) NULL,
  `status` ENUM('PENDING_ACTIVATION', 'ACTIVE', 'SUSPENDED', 'DISABLED') NOT NULL DEFAULT 'PENDING_ACTIVATION',
  `mfa_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `mfa_secret_encrypted` TEXT NULL,
  `mfa_recovery_codes` JSON NULL,
  `mfa_enrolled_at` DATETIME(6) NULL,
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  `password_changed_at` DATETIME(6) NULL,
  `failed_login_count` INT NOT NULL DEFAULT 0,
  `locked_until` DATETIME(6) NULL,
  `last_login_at` DATETIME(6) NULL,
  `last_login_ip` VARCHAR(64) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `created_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `updated_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `deleted_at` DATETIME(6) NULL,
  `deleted_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `delete_reason` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `users_deleted_at_idx` (`deleted_at`),
  UNIQUE KEY `users_email_key` (`email`),
  KEY `users_status_idx` (`status`),
  CONSTRAINT `users_delete_reason_chk` CHECK (`deleted_at` IS NULL OR `delete_reason` IS NOT NULL),
  CONSTRAINT `users_email_lowercase_chk` CHECK (CAST(`email` AS BINARY) = CAST(LOWER(`email`) AS BINARY) AND REGEXP_LIKE(`email`, '^[^@[:space:]]+@[^@[:space:]]+[.][a-z]{2,}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'warehouses' => <<<'SQL'
CREATE TABLE `warehouses` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `address` VARCHAR(300) NOT NULL,
  `city` VARCHAR(80) NOT NULL,
  `state` VARCHAR(80) NOT NULL,
  `pincode` VARCHAR(10) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `warehouses_code_key` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'warranties' => <<<'SQL'
CREATE TABLE `warranties` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `mattress_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `sale_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `dealer_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `customer_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `years` INT NOT NULL,
  `status` ENUM('ACTIVE', 'EXPIRED', 'VOID', 'SUPERSEDED') NOT NULL DEFAULT 'ACTIVE',
  `terms_version` VARCHAR(20) NOT NULL,
  `void_reason` TEXT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `created_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `updated_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `deleted_at` DATETIME(6) NULL,
  `deleted_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `delete_reason` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `warranties_dealer_id_status_idx` (`dealer_id`, `status`),
  KEY `warranties_end_date_idx` (`end_date`),
  UNIQUE KEY `warranties_mattress_id_key` (`mattress_id`),
  UNIQUE KEY `warranties_sale_id_key` (`sale_id`),
  CONSTRAINT `warranties_delete_reason_chk` CHECK (`deleted_at` IS NULL OR `delete_reason` IS NOT NULL),
  CONSTRAINT `warranties_period_chk` CHECK (`end_date` > `start_date`),
  CONSTRAINT `warranties_void_reason_chk` CHECK (`status` <> 'VOID' OR `void_reason` IS NOT NULL),
  CONSTRAINT `warranties_years_chk` CHECK (`years` BETWEEN 1 AND 30)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'warranty_claims' => <<<'SQL'
CREATE TABLE `warranty_claims` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `claim_number` VARCHAR(30) NOT NULL,
  `mattress_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `warranty_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `dealer_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `customer_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `issue_category` ENUM('SAGGING', 'FABRIC_TEAR', 'FOAM_DEGRADATION', 'SPRING_FAILURE', 'STITCHING', 'SIZE_MISMATCH', 'TRANSIT_DAMAGE', 'OTHER') NOT NULL,
  `reported_issue` VARCHAR(200) NOT NULL,
  `description` TEXT NOT NULL,
  `status` ENUM('SUBMITTED', 'UNDER_REVIEW', 'INFO_REQUESTED', 'APPROVED', 'REJECTED', 'REPLACED', 'CLOSED', 'WITHDRAWN') NOT NULL DEFAULT 'SUBMITTED',
  `risk_level` ENUM('LOW', 'MEDIUM', 'HIGH') NOT NULL DEFAULT 'LOW',
  `risk_score` INT NOT NULL DEFAULT 0,
  `risk_signals` JSON NOT NULL DEFAULT (JSON_ARRAY()),
  `risk_computed_at` DATETIME(6) NULL,
  `submitted_by_user_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `submitted_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `reviewed_by_user_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `decision_at` DATETIME(6) NULL,
  `decision_reason` TEXT NULL,
  `resolution` TEXT NULL,
  `closed_at` DATETIME(6) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `deleted_at` DATETIME(6) NULL,
  `deleted_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `delete_reason` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `claims_open_idx` (`status`, `submitted_at` DESC),
  UNIQUE KEY `warranty_claims_claim_number_key` (`claim_number`),
  KEY `warranty_claims_dealer_id_status_idx` (`dealer_id`, `status`),
  KEY `warranty_claims_mattress_id_idx` (`mattress_id`),
  KEY `warranty_claims_risk_level_idx` (`risk_level`),
  KEY `warranty_claims_status_submitted_at_idx` (`status`, `submitted_at`),
  CONSTRAINT `claims_decision_chk` CHECK (`status` NOT IN ('APPROVED','REJECTED','REPLACED') OR (`reviewed_by_user_id` IS NOT NULL AND `decision_at` IS NOT NULL)),
  CONSTRAINT `claims_delete_reason_chk` CHECK (`deleted_at` IS NULL OR `delete_reason` IS NOT NULL),
  CONSTRAINT `claims_number_format_chk` CHECK (REGEXP_LIKE(`claim_number`, '^CLM[0-9]{8,}$', 'c')),
  CONSTRAINT `claims_rejection_reason_chk` CHECK (`status` <> 'REJECTED' OR `decision_reason` IS NOT NULL),
  CONSTRAINT `claims_risk_score_chk` CHECK (`risk_score` BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'website_pages' => <<<'SQL'
CREATE TABLE `website_pages` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `slug` VARCHAR(120) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `status` ENUM('DRAFT', 'PUBLISHED', 'ARCHIVED') NOT NULL DEFAULT 'DRAFT',
  `hero` JSON NOT NULL DEFAULT (JSON_OBJECT()),
  `sections` JSON NOT NULL DEFAULT (JSON_ARRAY()),
  `published_at` DATETIME(6) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `created_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `updated_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `website_pages_slug_key` (`slug`),
  KEY `website_pages_status_idx` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'website_products' => <<<'SQL'
CREATE TABLE `website_products` (
  `id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `product_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `headline` VARCHAR(200) NOT NULL,
  `subheadline` VARCHAR(300) NULL,
  `body_html` TEXT NULL,
  `gallery` JSON NOT NULL DEFAULT (JSON_ARRAY()),
  `highlights` JSON NOT NULL DEFAULT (JSON_ARRAY()),
  `is_published` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 100,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `updated_by` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  PRIMARY KEY (`id`),
  KEY `website_products_is_published_sort_order_idx` (`is_published`, `sort_order`),
  UNIQUE KEY `website_products_product_id_key` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    ],
    'foreignKeys' => [
        <<<'SQL'
ALTER TABLE `backup_runs` ADD CONSTRAINT `backup_runs_requested_by_fkey` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `claim_events` ADD CONSTRAINT `claim_events_actor_user_id_fkey` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `claim_events` ADD CONSTRAINT `claim_events_claim_id_fkey` FOREIGN KEY (`claim_id`) REFERENCES `warranty_claims` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `claim_media` ADD CONSTRAINT `claim_media_claim_id_fkey` FOREIGN KEY (`claim_id`) REFERENCES `warranty_claims` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `claim_media` ADD CONSTRAINT `claim_media_uploaded_by_user_id_fkey` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `customers` ADD CONSTRAINT `customers_dealer_id_fkey` FOREIGN KEY (`dealer_id`) REFERENCES `dealers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `dealer_applications` ADD CONSTRAINT `dealer_applications_created_dealer_id_fkey` FOREIGN KEY (`created_dealer_id`) REFERENCES `dealers` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `dealer_receipt_items` ADD CONSTRAINT `dealer_receipt_items_mattress_id_fkey` FOREIGN KEY (`mattress_id`) REFERENCES `mattresses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `dealer_receipt_items` ADD CONSTRAINT `dealer_receipt_items_receipt_id_fkey` FOREIGN KEY (`receipt_id`) REFERENCES `dealer_receipts` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `dealer_receipts` ADD CONSTRAINT `dealer_receipts_dealer_id_fkey` FOREIGN KEY (`dealer_id`) REFERENCES `dealers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `dealer_receipts` ADD CONSTRAINT `dealer_receipts_dispatch_id_fkey` FOREIGN KEY (`dispatch_id`) REFERENCES `dispatches` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `dealer_receipts` ADD CONSTRAINT `dealer_receipts_received_by_user_id_fkey` FOREIGN KEY (`received_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `dealer_users` ADD CONSTRAINT `dealer_users_dealer_id_fkey` FOREIGN KEY (`dealer_id`) REFERENCES `dealers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `dealer_users` ADD CONSTRAINT `dealer_users_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `dispatch_items` ADD CONSTRAINT `dispatch_items_dispatch_id_fkey` FOREIGN KEY (`dispatch_id`) REFERENCES `dispatches` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `dispatch_items` ADD CONSTRAINT `dispatch_items_mattress_id_fkey` FOREIGN KEY (`mattress_id`) REFERENCES `mattresses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `dispatches` ADD CONSTRAINT `dispatches_created_by_fkey` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `dispatches` ADD CONSTRAINT `dispatches_dealer_id_fkey` FOREIGN KEY (`dealer_id`) REFERENCES `dealers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `dispatches` ADD CONSTRAINT `dispatches_warehouse_id_fkey` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `faqs` ADD CONSTRAINT `faqs_page_id_fkey` FOREIGN KEY (`page_id`) REFERENCES `website_pages` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `faqs` ADD CONSTRAINT `faqs_product_id_fkey` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `manufacturing_batches` ADD CONSTRAINT `manufacturing_batches_warehouse_id_fkey` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `mattress_events` ADD CONSTRAINT `mattress_events_actor_user_id_fkey` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `mattress_events` ADD CONSTRAINT `mattress_events_mattress_id_fkey` FOREIGN KEY (`mattress_id`) REFERENCES `mattresses` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `mattresses` ADD CONSTRAINT `mattresses_batch_id_fkey` FOREIGN KEY (`batch_id`) REFERENCES `manufacturing_batches` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `mattresses` ADD CONSTRAINT `mattresses_current_dealer_id_fkey` FOREIGN KEY (`current_dealer_id`) REFERENCES `dealers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `mattresses` ADD CONSTRAINT `mattresses_current_warehouse_id_fkey` FOREIGN KEY (`current_warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `mattresses` ADD CONSTRAINT `mattresses_product_variant_id_fkey` FOREIGN KEY (`product_variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `notifications` ADD CONSTRAINT `notifications_dealer_id_fkey` FOREIGN KEY (`dealer_id`) REFERENCES `dealers` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `notifications` ADD CONSTRAINT `notifications_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `password_reset_tokens` ADD CONSTRAINT `password_reset_tokens_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `product_variants` ADD CONSTRAINT `product_variants_product_id_fkey` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `refresh_tokens` ADD CONSTRAINT `refresh_tokens_session_id_fkey` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `replacements` ADD CONSTRAINT `replacements_approved_by_user_id_fkey` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `replacements` ADD CONSTRAINT `replacements_claim_id_fkey` FOREIGN KEY (`claim_id`) REFERENCES `warranty_claims` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `replacements` ADD CONSTRAINT `replacements_dispatch_id_fkey` FOREIGN KEY (`dispatch_id`) REFERENCES `dispatches` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `replacements` ADD CONSTRAINT `replacements_original_mattress_id_fkey` FOREIGN KEY (`original_mattress_id`) REFERENCES `mattresses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `replacements` ADD CONSTRAINT `replacements_replacement_mattress_id_fkey` FOREIGN KEY (`replacement_mattress_id`) REFERENCES `mattresses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `role_permissions` ADD CONSTRAINT `role_permissions_permission_id_fkey` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `role_permissions` ADD CONSTRAINT `role_permissions_role_id_fkey` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `sales` ADD CONSTRAINT `sales_customer_id_fkey` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `sales` ADD CONSTRAINT `sales_dealer_id_fkey` FOREIGN KEY (`dealer_id`) REFERENCES `dealers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `sales` ADD CONSTRAINT `sales_mattress_id_fkey` FOREIGN KEY (`mattress_id`) REFERENCES `mattresses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `sales` ADD CONSTRAINT `sales_sold_by_user_id_fkey` FOREIGN KEY (`sold_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `sessions` ADD CONSTRAINT `sessions_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `user_roles` ADD CONSTRAINT `user_roles_role_id_fkey` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `user_roles` ADD CONSTRAINT `user_roles_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `warranties` ADD CONSTRAINT `warranties_customer_id_fkey` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `warranties` ADD CONSTRAINT `warranties_dealer_id_fkey` FOREIGN KEY (`dealer_id`) REFERENCES `dealers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `warranties` ADD CONSTRAINT `warranties_mattress_id_fkey` FOREIGN KEY (`mattress_id`) REFERENCES `mattresses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `warranties` ADD CONSTRAINT `warranties_sale_id_fkey` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `warranty_claims` ADD CONSTRAINT `warranty_claims_customer_id_fkey` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `warranty_claims` ADD CONSTRAINT `warranty_claims_dealer_id_fkey` FOREIGN KEY (`dealer_id`) REFERENCES `dealers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `warranty_claims` ADD CONSTRAINT `warranty_claims_mattress_id_fkey` FOREIGN KEY (`mattress_id`) REFERENCES `mattresses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `warranty_claims` ADD CONSTRAINT `warranty_claims_reviewed_by_user_id_fkey` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `warranty_claims` ADD CONSTRAINT `warranty_claims_submitted_by_user_id_fkey` FOREIGN KEY (`submitted_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `warranty_claims` ADD CONSTRAINT `warranty_claims_warranty_id_fkey` FOREIGN KEY (`warranty_id`) REFERENCES `warranties` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE `website_products` ADD CONSTRAINT `website_products_product_id_fkey` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
SQL,
    ],
];
