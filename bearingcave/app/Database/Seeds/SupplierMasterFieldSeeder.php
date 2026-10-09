<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * PROPOSED supplier master-data field map (86 fields).
 *
 * The client's "Suppiler list" spreadsheet tab defines 86 column headings,
 * but that sheet could not be accessed from the build environment. These
 * fields cover the areas named in the brief (contact, legal, certifications,
 * financial, risk, audits, sanctions, performance, approvals, vendor status)
 * and are flagged is_confirmed = 0. When the real sheet is uploaded through
 * Admin → Supplier Master, any heading that does not match is registered
 * automatically, so no source column is ever dropped. Admins can then
 * rename/retarget fields to mirror the sheet exactly.
 */
class SupplierMasterFieldSeeder extends Seeder
{
    public static function fields(): array
    {
        return [
            'contact' => [
                ['Supplier Name', 'companies.legal_name'], ['Trade Name', 'companies.trade_name'], ['Contact Person', null], ['Designation', null],
                ['Email', 'companies.email'], ['Phone', 'companies.phone'], ['Website', 'companies.website'],
                ['Address Line 1', 'companies.address_line1'], ['City', 'companies.city'], ['State', 'companies.state'],
                ['Postal Code', 'companies.postal_code'], ['Country', 'companies.country_code'],
            ],
            'legal' => [
                ['Company Registration No', 'companies.registration_number'], ['Business Type', 'companies.business_type'], ['Year Established', 'companies.year_established'],
                ['Number of Employees', 'companies.employee_count'], ['GST Number', 'kyc_records.gst_number'], ['PAN', 'kyc_records.pan_number'], ['CIN', 'kyc_records.cin_number'],
                ['VAT Number', 'kyc_records.vat_number'], ['Tax ID', 'kyc_records.tax_id'], ['Directors / Owners', null], ['Ultimate Beneficial Owner', null], ['Supplier Type', 'supplier_profiles.supplier_type'],
            ],
            'capability' => [
                ['Product Categories', 'supplier_profiles.main_categories'], ['Brands Handled', 'supplier_profiles.brands_handled'], ['Production Capacity', 'supplier_profiles.production_capacity'],
                ['Export Markets', 'supplier_profiles.export_markets'], ['Warehouse Locations', null], ['Company Description', 'companies.description'],
            ],
            'certifications' => [
                ['ISO 9001', null], ['ISO 9001 Expiry', null], ['IATF 16949', null], ['IATF 16949 Expiry', null], ['AS9100', null], ['ISO 14001', null],
                ['RoHS Compliance', null], ['REACH Compliance', null], ['ESG Rating', null], ['Insurance Cover', null], ['Insurance Expiry', null], ['Labour Compliance', null],
            ],
            'financial' => [
                ['Annual Turnover', null], ['Financial Year', null], ['Net Profit', null], ['Credit Rating', null], ['Credit Rating Agency', null],
                ['Bank Name', null], ['Banking Reference', null], ['Pending Legal Cases', null], ['Bankruptcy History', null],
            ],
            'risk' => [
                ['Financial Risk', null], ['Operational Risk', null], ['Compliance Risk', null], ['Geographic Risk', null], ['Supply Risk', null], ['ESG Risk', null], ['Overall Risk Level', null],
            ],
            'audit' => [
                ['Last Site Audit Date', null], ['Site Audit Result', null], ['Auditor', null], ['Manufacturing Capability Score', null], ['Machinery Condition', null],
                ['Warehouse Management', null], ['Calibration Status', null], ['Quality Processes (PPAP/APQP/FMEA/CAPA)', null],
            ],
            'sanctions' => [
                ['UN Sanctions Check', null], ['OFAC Check', null], ['PEP Check', null], ['AML Check', null], ['Screening Date', null], ['Screening Provider', null],
            ],
            'performance' => [
                ['On-time Delivery %', null], ['Quality Rejection %', null], ['Cost Competitiveness', null], ['Average Response Time (hrs)', null], ['Service Level', null], ['Overall Performance Score', null],
            ],
            'approvals' => [
                ['Approved Categories', null], ['Approved Brands', null], ['Approved Products', null], ['Vendor Status', null], ['Approval Date', null],
                ['Membership Plan', null], ['Membership Expiry', null], ['Account Manager', null], 
            ],
        ];
    }

    public function run(): void
    {
        $sort = 0;
        $now  = date('Y-m-d H:i:s');
        foreach (self::fields() as $group => $fields) {
            foreach ($fields as [$heading, $target]) {
                $key = str_replace('-', '_', make_slug($heading, 90));
                if ($this->db->table('supplier_master_fields')->where('field_key', $key)->countAllResults() > 0) {
                    $sort++;

                    continue;
                }
                $this->db->table('supplier_master_fields')->insert([
                    'source_heading' => $heading, 'field_key' => $key, 'field_group' => $group, 'target' => $target,
                    'data_type' => 'string', 'sort_order' => $sort++, 'is_confirmed' => 0, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }
}
