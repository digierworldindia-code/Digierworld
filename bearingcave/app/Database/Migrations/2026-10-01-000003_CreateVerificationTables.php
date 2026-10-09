<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\SchemaHelper;
use CodeIgniter\Database\Migration;

/**
 * Supplier & buyer verification (12 verification modules).
 */
class CreateVerificationTables extends Migration
{
    use SchemaHelper;

    public function up(): void
    {
        $this->table('verification_applications', [
            'company_id'       => $this->ref(),
            'application_type' => $this->enum(['supplier', 'buyer']),
            'cycle'            => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 1],
            'status'           => $this->enum(['draft', 'submitted', 'in_review', 'approved', 'rejected', 'changes_requested', 'withdrawn'], 'draft'),
            'current_stage'    => $this->str(40, true),
            'submitted_at'     => $this->dt(),
            'decided_at'       => $this->dt(),
            'decided_by'       => $this->ref(true),
            'decision_notes'   => $this->text(),
        ] + $this->timestamps(), ['status', 'company_id'], [],
            [['company_id', 'companies', 'CASCADE'], ['decided_by', 'users', 'SET NULL']]
        );

        $this->table('verification_stages', [
            'application_id' => $this->ref(),
            'stage_key'      => $this->str(40),
            'sort_order'     => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            'status'         => $this->enum(['pending', 'in_review', 'passed', 'failed', 'waived', 'not_applicable'], 'pending'),
            'assigned_to'    => $this->ref(true),
            'reviewed_by'    => $this->ref(true),
            'reviewed_at'    => $this->dt(),
            'comments'       => $this->text(),
        ] + $this->timestamps(), ['status', 'assigned_to'], [['application_id', 'stage_key']],
            [['application_id', 'verification_applications', 'CASCADE'], ['assigned_to', 'users', 'SET NULL'], ['reviewed_by', 'users', 'SET NULL']]
        );

        $this->table('verification_events', [
            'application_id' => $this->ref(),
            'stage_key'      => $this->str(40, true),
            'action'         => $this->str(60),
            'user_id'        => $this->ref(true),
            'notes'          => $this->text(),
            'created_at'     => $this->dt(),
        ], ['application_id'], [],
            [['application_id', 'verification_applications', 'CASCADE'], ['user_id', 'users', 'SET NULL']]
        );

        // 9.2 KYC / KYB. Bank details stored encrypted.
        $this->table('kyc_records', [
            'company_id'            => $this->ref(),
            'gst_number'            => $this->str(30, true),
            'pan_number'            => $this->str(20, true),
            'cin_number'            => $this->str(30, true),
            'vat_number'            => $this->str(40, true),
            'tax_id'                => $this->str(60, true),
            'bank_account_name'     => $this->str(191, true),
            'bank_account_number_enc' => $this->text(),
            'bank_account_last4'    => $this->str(4, true),
            'bank_name'             => $this->str(191, true),
            'bank_ifsc_swift'       => $this->str(30, true),
            'bank_country'          => ['type' => 'CHAR', 'constraint' => 2, 'null' => true],
            'bank_validated'        => $this->enum(['pending', 'validated', 'failed'], 'pending'),
            'address_verified'      => $this->enum(['pending', 'verified', 'failed'], 'pending'),
            'owner_verified'        => $this->enum(['pending', 'verified', 'failed'], 'pending'),
            'ubo_verified'          => $this->enum(['pending', 'verified', 'failed'], 'pending'),
            'tax_verified'          => $this->enum(['pending', 'verified', 'failed'], 'pending'),
            'reviewer_notes'        => $this->text(),
            'reviewed_by'           => $this->ref(true),
            'reviewed_at'           => $this->dt(),
        ] + $this->timestamps(), [], ['company_id'],
            [['company_id', 'companies', 'CASCADE'], ['reviewed_by', 'users', 'SET NULL']]
        );

        // 9.3 Compliance certifications.
        $this->table('compliance_certifications', [
            'company_id'  => $this->ref(),
            'cert_type'   => $this->enum(['ISO9001', 'IATF16949', 'AS9100', 'ISO14001', 'ISO45001', 'RoHS', 'REACH', 'ESG', 'INSURANCE', 'LABOR', 'OTHER']),
            'cert_name'   => $this->str(191, true),
            'cert_number' => $this->str(100, true),
            'issuer'      => $this->str(191, true),
            'issued_on'   => $this->date(),
            'expires_on'  => $this->date(),
            'document_id' => $this->ref(true),
            'status'      => $this->enum(['pending', 'verified', 'rejected', 'expired'], 'pending'),
            'reviewer_notes' => $this->text(),
            'reviewed_by' => $this->ref(true),
            'reviewed_at' => $this->dt(),
        ] + $this->timestamps(), ['company_id', 'expires_on', 'status'], [],
            [['company_id', 'companies', 'CASCADE'], ['document_id', 'documents', 'SET NULL'], ['reviewed_by', 'users', 'SET NULL']]
        );

        // 9.4 Financial verification.
        $this->table('financial_assessments', [
            'company_id'          => $this->ref(),
            'fiscal_year'         => $this->str(9, true),
            'currency'            => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'INR'],
            'annual_turnover'     => $this->dec(18, 2, true),
            'net_profit'          => $this->dec(18, 2, true),
            'total_assets'        => $this->dec(18, 2, true),
            'total_liabilities'   => $this->dec(18, 2, true),
            'credit_rating'       => $this->str(20, true),
            'credit_agency'       => $this->str(100, true),
            'banking_reference'   => $this->text(),
            'legal_cases_count'   => $this->int(true, null),
            'legal_case_notes'    => $this->text(),
            'bankruptcy_history'  => $this->bool(0),
            'status'              => $this->enum(['pending', 'verified', 'rejected'], 'pending'),
            'reviewer_notes'      => $this->text(),
            'reviewed_by'         => $this->ref(true),
            'reviewed_at'         => $this->dt(),
        ] + $this->timestamps(), ['company_id'], [],
            [['company_id', 'companies', 'CASCADE'], ['reviewed_by', 'users', 'SET NULL']]
        );

        // 9.5 Risk assessment: factor scores 1 (low) .. 5 (high).
        $this->table('risk_assessments', [
            'company_id'       => $this->ref(),
            'application_id'   => $this->ref(true),
            'financial_risk'   => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'operational_risk' => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'compliance_risk'  => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'geographic_risk'  => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'supply_risk'      => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'esg_risk'         => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'weighted_score'   => $this->dec(5, 2, true),
            'risk_level'       => $this->enum(['LOW', 'MEDIUM', 'HIGH'], null, true),
            'evidence_notes'   => $this->text(),
            'assessed_by'      => $this->ref(true),
            'assessed_at'      => $this->dt(),
        ] + $this->timestamps(), ['company_id', 'risk_level'], [],
            [['company_id', 'companies', 'CASCADE'], ['application_id', 'verification_applications', 'SET NULL'], ['assessed_by', 'users', 'SET NULL']]
        );

        // 9.6 Quality assessment.
        $this->table('quality_assessments', [
            'company_id'           => $this->ref(),
            'has_inspection_system' => $this->bool(0),
            'ppap'                 => $this->enum(['yes', 'no', 'partial', 'na'], 'na'),
            'apqp'                 => $this->enum(['yes', 'no', 'partial', 'na'], 'na'),
            'fmea'                 => $this->enum(['yes', 'no', 'partial', 'na'], 'na'),
            'capa'                 => $this->enum(['yes', 'no', 'partial', 'na'], 'na'),
            'qc_process_notes'     => $this->text(),
            'rejection_rate_pct'   => $this->dec(5, 2, true),
            'quality_certificates' => $this->text(),
            'score'                => $this->dec(5, 2, true),
            'status'               => $this->enum(['pending', 'passed', 'failed'], 'pending'),
            'reviewer_notes'       => $this->text(),
            'reviewed_by'          => $this->ref(true),
            'reviewed_at'          => $this->dt(),
        ] + $this->timestamps(), ['company_id'], [],
            [['company_id', 'companies', 'CASCADE'], ['reviewed_by', 'users', 'SET NULL']]
        );

        // 9.7 Site audit: dimension scores 1..5.
        $this->table('site_audits', [
            'company_id'                => $this->ref(),
            'scheduled_on'              => $this->date(),
            'conducted_on'              => $this->date(),
            'auditor_user_id'           => $this->ref(true),
            'external_auditor'          => $this->str(191, true),
            'site_address'              => $this->text(),
            'manufacturing_capability'  => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'production_capacity'       => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'machinery_condition'       => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'warehouse_management'      => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'calibration'               => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'workforce_capability'      => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'quality_processes'         => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'findings'                  => $this->text(),
            'result'                    => $this->enum(['scheduled', 'pass', 'conditional', 'fail', 'cancelled'], 'scheduled'),
            'report_document_id'        => $this->ref(true),
        ] + $this->timestamps(), ['company_id'], [],
            [['company_id', 'companies', 'CASCADE'], ['auditor_user_id', 'users', 'SET NULL'], ['report_document_id', 'documents', 'SET NULL']]
        );

        // 9.10 Sanctions / PEP / AML screening results. Only real results
        // from a configured provider or a documented manual check.
        $this->table('sanctions_screenings', [
            'company_id'    => $this->ref(),
            'subject_type'  => $this->enum(['company', 'director']),
            'subject_name'  => $this->str(191),
            'provider'      => $this->str(60),
            'lists_checked' => $this->str(191, true),
            'result'        => $this->enum(['clear', 'potential_match', 'confirmed_match', 'error']),
            'provider_reference' => $this->str(191, true),
            'notes'         => $this->text(),
            'screened_by'   => $this->ref(true),
            'screened_at'   => $this->dt(false),
        ] + $this->timestamps(), ['company_id', 'result'], [],
            [['company_id', 'companies', 'CASCADE'], ['screened_by', 'users', 'SET NULL']]
        );

        // 9.12 Approved Vendor List.
        $this->table('approved_vendors', [
            'company_id'  => $this->ref(),
            'scope_type'  => $this->enum(['general', 'category', 'brand', 'product']),
            'scope_id'    => $this->ref(true),
            'status'      => $this->enum(['approved', 'suspended', 'revoked'], 'approved'),
            'valid_until' => $this->date(),
            'notes'       => $this->text(),
            'approved_by' => $this->ref(true),
            'approved_at' => $this->dt(),
        ] + $this->timestamps(), ['status', ['scope_type', 'scope_id']], [['company_id', 'scope_type', 'scope_id']],
            [['company_id', 'companies', 'CASCADE'], ['approved_by', 'users', 'SET NULL']]
        );

        // 9.11 Performance monitoring snapshots (computed from real records).
        $this->table('supplier_performance', [
            'company_id'            => $this->ref(),
            'period_start'          => $this->date(false),
            'period_end'            => $this->date(false),
            'orders_count'          => $this->int(),
            'on_time_delivery_pct'  => $this->dec(5, 2, true),
            'quality_rejection_pct' => $this->dec(5, 2, true),
            'cost_competitiveness'  => $this->dec(5, 2, true),
            'avg_response_hours'    => $this->dec(8, 2, true),
            'rfq_response_rate_pct' => $this->dec(5, 2, true),
            'service_level'         => $this->dec(5, 2, true),
            'overall_score'         => $this->dec(5, 2, true),
            'source'                => $this->enum(['computed', 'manual'], 'computed'),
            'notes'                 => $this->text(),
            'computed_at'           => $this->dt(),
        ] + $this->timestamps(), [], [['company_id', 'period_start', 'period_end']],
            [['company_id', 'companies', 'CASCADE']]
        );
    }

    public function down(): void
    {
        foreach (['supplier_performance', 'approved_vendors', 'sanctions_screenings', 'site_audits', 'quality_assessments', 'risk_assessments', 'financial_assessments', 'compliance_certifications', 'kyc_records', 'verification_events', 'verification_stages', 'verification_applications'] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}
