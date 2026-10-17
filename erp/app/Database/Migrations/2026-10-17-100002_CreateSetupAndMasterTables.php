<?php

namespace App\Database\Migrations;

use App\Database\SqlMigration;

/**
 * Company identity, branding assets, templates, signature policy, states,
 * ledger accounts, bank/cash accounts, tax rates/treatments, document series,
 * services catalogue and the unified contacts master.
 */
class CreateSetupAndMasterTables extends SqlMigration
{
    public function up(): void
    {
        $a = self::AUDIT_COLS;

        $this->run([
            "CREATE TABLE states (
                code CHAR(2) NOT NULL PRIMARY KEY COMMENT 'GST state code',
                name VARCHAR(80) NOT NULL,
                alpha_code VARCHAR(4) NULL,
                is_ut TINYINT(1) NOT NULL DEFAULT 0,
                uses_utgst TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'UT without legislature → CGST+UTGST',
                is_special TINYINT(1) NOT NULL DEFAULT 0 COMMENT '96 Other Country / 97 Other Territory',
                is_active TINYINT(1) NOT NULL DEFAULT 1
            )",

            "CREATE TABLE company_settings (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
                legal_name VARCHAR(200) NULL,
                trade_name VARCHAR(200) NULL,
                tagline VARCHAR(200) NULL,
                brand_description VARCHAR(500) NULL,
                gstin CHAR(15) NULL,
                pan CHAR(10) NULL,
                gst_registration_type ENUM('regular','composition','unregistered') NOT NULL DEFAULT 'regular',
                state_code CHAR(2) NULL,
                reg_address_line1 VARCHAR(200) NULL,
                reg_address_line2 VARCHAR(200) NULL,
                reg_city VARCHAR(80) NULL,
                reg_district VARCHAR(80) NULL,
                reg_pincode CHAR(6) NULL,
                bill_address_same TINYINT(1) NOT NULL DEFAULT 1,
                bill_address_line1 VARCHAR(200) NULL,
                bill_address_line2 VARCHAR(200) NULL,
                bill_city VARCHAR(80) NULL,
                bill_district VARCHAR(80) NULL,
                bill_state_code CHAR(2) NULL,
                bill_pincode CHAR(6) NULL,
                phone VARCHAR(20) NULL,
                alt_phone VARCHAR(20) NULL,
                email VARCHAR(150) NULL,
                alt_email VARCHAR(150) NULL,
                website VARCHAR(150) NULL,
                business_category VARCHAR(150) NULL,
                contact_persons JSON NULL,
                einvoice_applicable TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'decision recorded by owner/accountant',
                lut_number VARCHAR(50) NULL,
                lut_valid_till DATE NULL,
                invoice_footer VARCHAR(500) NULL,
                invoice_terms TEXT NULL,
                legal_disclaimer VARCHAR(500) NULL,
                fy_start_month TINYINT UNSIGNED NOT NULL DEFAULT 4,
                currency CHAR(3) NOT NULL DEFAULT 'INR',
                timezone VARCHAR(40) NOT NULL DEFAULT 'Asia/Kolkata',
                default_payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 15,
                rounding_policy ENUM('none','nearest_rupee') NOT NULL DEFAULT 'nearest_rupee',
                default_price_mode ENUM('exclusive','inclusive') NOT NULL DEFAULT 'exclusive',
                default_template_id INT UNSIGNED NULL,
                brand_primary VARCHAR(9) NOT NULL DEFAULT '#111111',
                brand_accent VARCHAR(9) NOT NULL DEFAULT '#B08D57',
                setup_steps JSON NULL COMMENT 'wizard completion status',
                setup_completed_at DATETIME NULL,
                profile_version INT UNSIGNED NOT NULL DEFAULT 1,
                {$a},
                CONSTRAINT chk_cs_singleton CHECK (id = 1),
                CONSTRAINT fk_cs_state FOREIGN KEY (state_code) REFERENCES states(code),
                CONSTRAINT fk_cs_bstate FOREIGN KEY (bill_state_code) REFERENCES states(code)
            )",

            "CREATE TABLE company_profile_versions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                profile_version INT UNSIGNED NOT NULL,
                data JSON NOT NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_cpv (profile_version)
            )",

            "CREATE TABLE ledger_accounts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(20) NOT NULL,
                name VARCHAR(150) NOT NULL,
                account_group ENUM('asset','liability','equity','income','expense') NOT NULL,
                sub_type VARCHAR(40) NOT NULL,
                parent_id INT UNSIGNED NULL,
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                {$a},
                UNIQUE KEY uq_la_code (code),
                KEY idx_la_sub (sub_type),
                CONSTRAINT fk_la_parent FOREIGN KEY (parent_id) REFERENCES ledger_accounts(id)
            )",

            "CREATE TABLE bank_cash_accounts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(30) NOT NULL,
                account_kind ENUM('cash','bank','upi_clearing','card_clearing','other') NOT NULL,
                ownership ENUM('company','owner_personal','other') NOT NULL DEFAULT 'company',
                classification_note VARCHAR(255) NULL COMMENT 'accountant-reviewed treatment of personal accounts',
                display_name VARCHAR(120) NOT NULL,
                bank_name VARCHAR(120) NULL,
                beneficiary_name VARCHAR(150) NULL,
                account_number_enc TEXT NULL COMMENT 'encrypted',
                account_last4 CHAR(4) NULL,
                ifsc CHAR(11) NULL,
                branch VARCHAR(120) NULL,
                account_type ENUM('current','savings','od','cc','wallet','other') NULL,
                upi_id VARCHAR(100) NULL,
                cif_enc TEXT NULL COMMENT 'encrypted; internal only, never printed/exported/sent to AI',
                qr_asset_id INT UNSIGNED NULL,
                display_priority SMALLINT NOT NULL DEFAULT 100,
                show_on_invoice TINYINT(1) NOT NULL DEFAULT 0,
                is_default_on_invoice TINYINT(1) NOT NULL DEFAULT 0,
                print_approved_by INT UNSIGNED NULL COMMENT 'required before a personal account can be printed',
                print_approved_at DATETIME NULL,
                ledger_account_id INT UNSIGNED NOT NULL,
                opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                opening_date DATE NULL,
                requires_review TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'flag receipts for reconciliation review',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                {$a},
                UNIQUE KEY uq_bca_code (code),
                UNIQUE KEY uq_bca_ledger (ledger_account_id),
                CONSTRAINT fk_bca_ledger FOREIGN KEY (ledger_account_id) REFERENCES ledger_accounts(id)
            )",

            "CREATE TABLE branding_assets (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                asset_type ENUM('logo','signature','stamp','sign_stamp','upi_qr','letterhead') NOT NULL,
                version INT UNSIGNED NOT NULL,
                stored_file_id BIGINT UNSIGNED NOT NULL,
                width_px INT UNSIGNED NULL,
                height_px INT UNSIGNED NULL,
                position_preset ENUM('top_left','top_center','top_right','bottom_left','bottom_center','bottom_right') NOT NULL DEFAULT 'top_left',
                display_width_mm DECIMAL(5,1) NOT NULL DEFAULT 40.0,
                enabled_doc_types JSON NULL COMMENT 'doc types this asset may appear on',
                bank_account_id INT UNSIGNED NULL COMMENT 'for UPI QR',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                replaced_at DATETIME NULL,
                {$a},
                UNIQUE KEY uq_ba (asset_type, version),
                CONSTRAINT fk_ba_file FOREIGN KEY (stored_file_id) REFERENCES stored_files(id),
                CONSTRAINT fk_ba_bank FOREIGN KEY (bank_account_id) REFERENCES bank_cash_accounts(id) ON DELETE SET NULL
            )",

            "ALTER TABLE bank_cash_accounts ADD CONSTRAINT fk_bca_qr FOREIGN KEY (qr_asset_id) REFERENCES branding_assets(id) ON DELETE SET NULL",

            "CREATE TABLE document_templates (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(40) NOT NULL,
                name VARCHAR(100) NOT NULL,
                version INT UNSIGNED NOT NULL DEFAULT 1,
                layout ENUM('classic','minimal','premium') NOT NULL,
                paper ENUM('A4','A5') NOT NULL DEFAULT 'A4',
                settings JSON NOT NULL,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                {$a},
                UNIQUE KEY uq_dt (code, version)
            )",

            "CREATE TABLE signature_policies (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                role_id INT UNSIGNED NULL,
                user_id INT UNSIGNED NULL,
                doc_type VARCHAR(30) NOT NULL,
                allowed TINYINT(1) NOT NULL DEFAULT 0,
                approved_by INT UNSIGNED NULL COMMENT 'owner approval',
                approved_at DATETIME NULL,
                {$a},
                UNIQUE KEY uq_sp (role_id, user_id, doc_type),
                CONSTRAINT fk_sp_role FOREIGN KEY (role_id) REFERENCES roles(id),
                CONSTRAINT fk_sp_user FOREIGN KEY (user_id) REFERENCES users(id),
                CONSTRAINT chk_sp_subject CHECK ((role_id IS NULL) <> (user_id IS NULL))
            )",

            "CREATE TABLE tax_treatments (
                code VARCHAR(30) NOT NULL PRIMARY KEY,
                label VARCHAR(100) NOT NULL,
                charges_tax TINYINT(1) NOT NULL COMMENT 'supplier charges GST on invoice',
                gstr1_section VARCHAR(40) NULL,
                requires_reason TINYINT(1) NOT NULL DEFAULT 0,
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                notes VARCHAR(500) NULL,
                sort_order SMALLINT NOT NULL DEFAULT 0
            )",

            "CREATE TABLE tax_rates (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(60) NOT NULL,
                rate DECIMAL(6,3) NOT NULL COMMENT 'total GST rate %',
                cess_rate DECIMAL(6,3) NOT NULL DEFAULT 0.000,
                effective_from DATE NOT NULL,
                effective_to DATE NULL,
                review_status ENUM('suggested','accountant_reviewed') NOT NULL DEFAULT 'suggested',
                reviewed_by INT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                notes VARCHAR(255) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                {$a},
                KEY idx_tr_rate (rate, effective_from),
                CONSTRAINT chk_tr_rate CHECK (rate >= 0 AND rate <= 100)
            )",

            "CREATE TABLE document_sequences (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                series_code VARCHAR(30) NOT NULL,
                doc_type VARCHAR(30) NOT NULL,
                name VARCHAR(100) NOT NULL,
                financial_year_id INT UNSIGNED NOT NULL,
                prefix VARCHAR(16) NOT NULL,
                suffix VARCHAR(10) NOT NULL DEFAULT '',
                next_number INT UNSIGNED NOT NULL DEFAULT 1,
                padding TINYINT UNSIGNED NOT NULL DEFAULT 4,
                is_statutory TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'GST Rule 46: max 16 chars, unique per FY',
                max_length TINYINT UNSIGNED NOT NULL DEFAULT 16,
                is_default TINYINT(1) NOT NULL DEFAULT 1,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                {$a},
                UNIQUE KEY uq_ds (series_code, financial_year_id),
                UNIQUE KEY uq_ds_prefix (prefix, suffix, financial_year_id),
                KEY idx_ds_type (doc_type, financial_year_id, is_default),
                CONSTRAINT fk_ds_fy FOREIGN KEY (financial_year_id) REFERENCES financial_years(id),
                CONSTRAINT chk_ds_next CHECK (next_number >= 1)
            )",

            "CREATE TABLE services (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(30) NOT NULL,
                name VARCHAR(150) NOT NULL,
                category VARCHAR(80) NULL,
                description TEXT NULL,
                sac_code VARCHAR(8) NULL,
                unit VARCHAR(20) NOT NULL DEFAULT 'Service',
                default_rate DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                default_tax_rate_id INT UNSIGNED NULL,
                default_tax_treatment VARCHAR(30) NOT NULL DEFAULT 'regular',
                pos_rule ENUM('default','review_required') NOT NULL DEFAULT 'default' COMMENT 'special place-of-supply rules need human review',
                is_recurring_type TINYINT(1) NOT NULL DEFAULT 0,
                review_status ENUM('suggested','accountant_reviewed') NOT NULL DEFAULT 'suggested',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                {$a},
                UNIQUE KEY uq_svc_code (code),
                CONSTRAINT fk_svc_tax FOREIGN KEY (default_tax_rate_id) REFERENCES tax_rates(id) ON DELETE SET NULL,
                CONSTRAINT fk_svc_treat FOREIGN KEY (default_tax_treatment) REFERENCES tax_treatments(code)
            )",

            "CREATE TABLE contacts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                contact_code VARCHAR(20) NOT NULL,
                is_customer TINYINT(1) NOT NULL DEFAULT 1,
                is_vendor TINYINT(1) NOT NULL DEFAULT 0,
                is_prospect TINYINT(1) NOT NULL DEFAULT 0,
                entity_kind ENUM('company','individual') NOT NULL DEFAULT 'company',
                name VARCHAR(200) NOT NULL,
                display_name VARCHAR(200) NULL,
                legal_name VARCHAR(200) NULL,
                contact_person VARCHAR(150) NULL,
                phone VARCHAR(20) NULL,
                phone_normalized VARCHAR(15) NULL,
                alt_phone VARCHAR(20) NULL,
                whatsapp VARCHAR(20) NULL,
                email VARCHAR(150) NULL,
                gstin CHAR(15) NULL,
                gst_registration_type ENUM('registered','unregistered','composition','consumer','sez','overseas','uin') NOT NULL DEFAULT 'unregistered',
                pan CHAR(10) NULL,
                state_code CHAR(2) NULL,
                state_source ENUM('gstin','address','manual') NULL,
                city VARCHAR(80) NULL,
                district VARCHAR(80) NULL,
                pincode CHAR(6) NULL,
                industry VARCHAR(100) NULL,
                source VARCHAR(80) NULL,
                category VARCHAR(80) NULL,
                tags VARCHAR(255) NULL,
                credit_days SMALLINT UNSIGNED NULL,
                credit_limit DECIMAL(15,2) NULL,
                opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                opening_balance_type ENUM('dr','cr') NOT NULL DEFAULT 'dr',
                notes TEXT NULL,
                dnd TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                merged_into_id BIGINT UNSIGNED NULL,
                {$a},
                deleted_at DATETIME NULL,
                UNIQUE KEY uq_contact_code (contact_code),
                KEY idx_contact_phone (phone_normalized),
                KEY idx_contact_gstin (gstin),
                KEY idx_contact_name (name),
                KEY idx_contact_flags (is_customer, is_vendor, is_active),
                CONSTRAINT fk_contact_state FOREIGN KEY (state_code) REFERENCES states(code),
                CONSTRAINT fk_contact_merged FOREIGN KEY (merged_into_id) REFERENCES contacts(id)
            )",

            "CREATE TABLE contact_addresses (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                contact_id BIGINT UNSIGNED NOT NULL,
                address_type ENUM('billing','service','shipping','other') NOT NULL DEFAULT 'billing',
                line1 VARCHAR(200) NOT NULL,
                line2 VARCHAR(200) NULL,
                city VARCHAR(80) NULL,
                district VARCHAR(80) NULL,
                state_code CHAR(2) NULL,
                pincode CHAR(6) NULL,
                country CHAR(2) NOT NULL DEFAULT 'IN',
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                {$a},
                KEY idx_ca_contact (contact_id, address_type),
                CONSTRAINT fk_ca_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
                CONSTRAINT fk_ca_state FOREIGN KEY (state_code) REFERENCES states(code)
            )",

            "CREATE TABLE contact_people (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                contact_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(150) NOT NULL,
                designation VARCHAR(100) NULL,
                phone VARCHAR(20) NULL,
                email VARCHAR(150) NULL,
                is_primary TINYINT(1) NOT NULL DEFAULT 0,
                {$a},
                CONSTRAINT fk_cp_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
            )",

            "CREATE TABLE contact_files (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                contact_id BIGINT UNSIGNED NOT NULL,
                stored_file_id BIGINT UNSIGNED NOT NULL,
                label VARCHAR(150) NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_cf_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
                CONSTRAINT fk_cf_file FOREIGN KEY (stored_file_id) REFERENCES stored_files(id)
            )",

            "CREATE TABLE contact_merges (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                source_contact_id BIGINT UNSIGNED NOT NULL,
                target_contact_id BIGINT UNSIGNED NOT NULL,
                snapshot JSON NOT NULL,
                merged_by INT UNSIGNED NULL,
                merged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_cm_src FOREIGN KEY (source_contact_id) REFERENCES contacts(id),
                CONSTRAINT fk_cm_tgt FOREIGN KEY (target_contact_id) REFERENCES contacts(id)
            )",
        ]);
    }

    public function down(): void
    {
        $this->dropTables([
            'contact_merges', 'contact_files', 'contact_people', 'contact_addresses', 'contacts', 'services',
            'document_sequences', 'tax_rates', 'tax_treatments', 'signature_policies', 'document_templates',
            'branding_assets', 'bank_cash_accounts', 'ledger_accounts', 'company_profile_versions',
            'company_settings', 'states',
        ]);
    }
}
