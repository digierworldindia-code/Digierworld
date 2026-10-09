<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\SchemaHelper;
use CodeIgniter\Database\Migration;

/**
 * Business accounts (buyers and suppliers), their members, profiles,
 * documents and the 86-column supplier master-data store.
 */
class CreateCompanyTables extends Migration
{
    use SchemaHelper;

    public function up(): void
    {
        $this->table('companies', [
            'uuid'                 => ['type' => 'CHAR', 'constraint' => 36],
            'company_type'         => $this->enum(['buyer', 'supplier']),
            'legal_name'           => $this->str(191),
            'trade_name'           => $this->str(191, true),
            // Shown instead of the real name whenever identity is confidential.
            'public_alias'         => $this->str(60),
            'slug'                 => $this->str(191),
            'registration_number'  => $this->str(80, true),
            'business_type'        => $this->str(60, true),
            'year_established'     => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
            'employee_count'       => $this->str(30, true),
            'country_code'         => ['type' => 'CHAR', 'constraint' => 2],
            'state'                => $this->str(100, true),
            'city'                 => $this->str(100, true),
            'address_line1'        => $this->str(191, true),
            'address_line2'        => $this->str(191, true),
            'postal_code'          => $this->str(20, true),
            'phone'                => $this->str(40, true),
            'email'                => $this->str(191, true),
            'website'              => $this->str(191, true),
            'description'          => $this->text(),
            'logo_path'            => $this->str(191, true),
            'verification_status'  => $this->enum(['unverified', 'in_review', 'verified', 'rejected', 'suspended'], 'unverified'),
            'verified_at'          => $this->dt(),
            'status'               => $this->enum(['active', 'suspended', 'closed'], 'active'),
            'suspension_reason'    => $this->text(),
            'public_profile_enabled' => $this->bool(0),
            'show_contact_public'  => $this->bool(0),
            'account_manager_user_id' => $this->ref(true),
            'is_sample'            => $this->bool(0),
        ] + $this->timestamps(true),
            ['company_type', 'verification_status', 'country_code', 'status'],
            ['uuid', 'slug'],
            [['account_manager_user_id', 'users', 'SET NULL'], ['country_code', 'countries', 'RESTRICT', 'iso2']]
        );

        $this->table('company_members', [
            'company_id'  => $this->ref(),
            'user_id'     => $this->ref(),
            'member_role' => $this->enum(['owner', 'admin', 'employee'], 'employee'),
            'job_title'   => $this->str(100, true),
            'status'      => $this->enum(['active', 'invited', 'disabled'], 'active'),
        ] + $this->timestamps(),
            [], [['company_id', 'user_id'], 'user_id'],
            [['company_id', 'companies', 'CASCADE'], ['user_id', 'users', 'CASCADE']]
        );

        // Fine-grained permissions for non-owner employees (see CompanyPermission).
        $this->table('company_member_permissions', [
            'company_member_id' => $this->ref(),
            'permission_key'    => $this->str(80),
        ], [], [['company_member_id', 'permission_key']],
            [['company_member_id', 'company_members', 'CASCADE']]
        );

        $this->table('buyer_profiles', [
            'company_id'              => $this->ref(),
            'buyer_category'          => $this->str(80, true),
            'annual_purchase_volume'  => $this->str(60, true),
            'interested_categories'   => $this->text(),
            'preferred_currency'      => ['type' => 'CHAR', 'constraint' => 3, 'null' => true],
            'engagement_score'        => $this->dec(5, 2, false, '0.00'),
            'rfq_genuineness_score'   => $this->dec(5, 2, false, '0.00'),
            'transaction_score'       => $this->dec(5, 2, false, '0.00'),
            'scores_computed_at'      => $this->dt(),
            'restricted_inventory_access' => $this->bool(0),
        ] + $this->timestamps(), [], ['company_id'], [['company_id', 'companies', 'CASCADE']]);

        $this->table('supplier_profiles', [
            'company_id'           => $this->ref(),
            'supplier_type'        => $this->enum(['manufacturer', 'oem_supplier', 'aftermarket_supplier', 'distributor', 'trader', 'stockist', 'other'], 'manufacturer'),
            'main_categories'      => $this->text(),
            'brands_handled'       => $this->text(),
            'production_capacity'  => $this->str(191, true),
            'warehouse_count'      => $this->int(true, null),
            'export_markets'       => $this->text(),
            'logistics_preference' => $this->enum(['platform_managed', 'supplier_direct', 'both'], 'platform_managed'),
            'default_confidential' => $this->bool(1),
            'ranking_score'        => $this->dec(6, 2, false, '0.00'),
            'performance_score'    => $this->dec(5, 2, true),
            'risk_level'           => $this->enum(['LOW', 'MEDIUM', 'HIGH'], null, true),
        ] + $this->timestamps(), ['ranking_score'], ['company_id'], [['company_id', 'companies', 'CASCADE']]);

        // Supplier-level default country visibility rules.
        $this->table('company_country_rules', [
            'company_id'   => $this->ref(),
            'country_code' => ['type' => 'CHAR', 'constraint' => 2],
            'rule'         => $this->enum(['allow', 'deny']),
        ], [], [['company_id', 'country_code']],
            [['company_id', 'companies', 'CASCADE'], ['country_code', 'countries', 'CASCADE', 'iso2']]
        );

        $this->table('company_directors', [
            'company_id'        => $this->ref(),
            'full_name'         => $this->str(191),
            'designation'       => $this->str(100, true),
            'nationality'       => ['type' => 'CHAR', 'constraint' => 2, 'null' => true],
            'id_document_type'  => $this->str(60, true),
            'id_document_number_enc' => $this->text(),
            'ownership_percent' => $this->dec(5, 2, true),
            'is_ubo'            => $this->bool(0),
            'is_pep_declared'   => $this->bool(0),
            'verification_status' => $this->enum(['pending', 'verified', 'rejected'], 'pending'),
        ] + $this->timestamps(), ['company_id'], [], [['company_id', 'companies', 'CASCADE']]);

        // Private, versioned document store. Files live outside the webroot.
        $this->table('documents', [
            'uuid'           => ['type' => 'CHAR', 'constraint' => 36],
            'company_id'     => $this->ref(true),
            'uploaded_by'    => $this->ref(true),
            'entity_type'    => $this->str(40),
            'entity_id'      => $this->ref(true),
            'doc_type'       => $this->str(60),
            'title'          => $this->str(191),
            'original_name'  => $this->str(191),
            'stored_path'    => $this->str(255),
            'mime_type'      => $this->str(100),
            'size_bytes'     => $this->int(),
            'sha256'         => ['type' => 'CHAR', 'constraint' => 64],
            'version'        => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 1],
            'previous_version_id' => $this->ref(true),
            'is_current'     => $this->bool(1),
            'issued_on'      => $this->date(),
            'expires_on'     => $this->date(),
            'expiry_reminder_sent_at' => $this->dt(),
            'visibility'     => $this->enum(['private', 'platform', 'verified_buyers', 'public'], 'private'),
            'review_status'  => $this->enum(['pending', 'approved', 'rejected'], 'pending'),
            'review_comment' => $this->text(),
            'reviewed_by'    => $this->ref(true),
            'reviewed_at'    => $this->dt(),
        ] + $this->timestamps(true),
            [['entity_type', 'entity_id'], 'expires_on', 'review_status'],
            ['uuid'],
            [['company_id', 'companies', 'CASCADE'], ['uploaded_by', 'users', 'SET NULL'], ['reviewed_by', 'users', 'SET NULL'], ['previous_version_id', 'documents', 'SET NULL']]
        );

        // Mapping of the Supplier List spreadsheet headings (86 columns) to
        // normalized targets. Unmapped columns are preserved as attributes.
        $this->table('supplier_master_fields', [
            'source_heading' => $this->str(191),
            'field_key'      => $this->str(100),
            'field_group'    => $this->str(60),
            'target'         => $this->str(120, true),
            'data_type'      => $this->enum(['string', 'text', 'number', 'decimal', 'date', 'bool', 'email', 'url'], 'string'),
            'sort_order'     => $this->int(),
            'is_confirmed'   => $this->bool(0),
        ] + $this->timestamps(), ['field_group'], ['field_key']);

        $this->table('supplier_master_attributes', [
            'company_id' => $this->ref(),
            'field_key'  => $this->str(100),
            'value'      => $this->text(),
            'import_job_id' => $this->ref(true),
        ] + $this->timestamps(), [], [['company_id', 'field_key']], [['company_id', 'companies', 'CASCADE']]);
    }

    public function down(): void
    {
        foreach (['supplier_master_attributes', 'supplier_master_fields', 'documents', 'company_directors', 'company_country_rules', 'supplier_profiles', 'buyer_profiles', 'company_member_permissions', 'company_members', 'companies'] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}
