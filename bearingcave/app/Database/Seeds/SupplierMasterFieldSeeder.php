<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Supplier master-data fields = the 86 column headings of the client's
 * "Suppiler list" tab (BearingCave workbook, supplied 2026-10-13), in sheet
 * order and with the sheet's own spelling (e.g. "Manufacturing Catagory").
 *
 * Headings that correspond to a normalized column carry a target; every
 * value is also kept in supplier_master_attributes, so no column is lost.
 * "Bank account" is never stored in plain text: it is encrypted into
 * kyc_records and only a masked value is kept as an attribute.
 */
class SupplierMasterFieldSeeder extends Seeder
{
    /**
     * @return array<string, list<array{0:string,1:?string}>> group => [[heading, target]]
     */
    public static function fields(): array
    {
        return [
            'contact' => [
                ['SKU', null], ['Company Name', 'companies.legal_name'], ['Contact Person', null], ['Designation', null],
                ['Manufacturing Catagory', 'supplier_profiles.main_categories'], ['Address 1', 'companies.address_line1'], ['State', 'companies.state'],
                ['Country', 'companies.country_code'], ['Land Line No.', 'companies.phone'], ['Mobile no.', null], ['Fax No.', null],
                ['Email', 'companies.email'], ['Website', 'companies.website'], ['Instagram', null], ['Facebook', null], ['Linkedin', null],
            ],
            'registration_kyc' => [
                ['GST Registration', 'kyc_records.gst_number'], ['PAN Card', 'kyc_records.pan_number'], ['CIN', 'kyc_records.cin_number'],
                ['VAT', 'kyc_records.vat_number'], ['Tax ID validation', 'kyc_records.tax_id'], ['Bank account', 'kyc_records.bank_account_number'],
                ['Certificate of Incorporation', null], ['MSME Certificate', null], ['Import Export Code (IEC)', null], ['Director/Owner verification', null],
            ],
            'compliance' => [
                ['ISO certifications', null], ['IATF 16949 (Automotive)', null], ['AS9100 (Aerospace)', null], ['RoHS & REACH compliance', null],
                ['ESG compliance', null], ['Insurance certificates', null], ['Labor law compliance', null],
            ],
            'financial' => [
                ['Credit rating', null], ['Annual turnover', null], ['Profitability', null], ['Balance sheet review', null],
                ['Banking references', null], ['Outstanding legal cases', null], ['Bankruptcy checks', null],
            ],
            'risk' => [
                ['Risk outputs', null], ['Financial Risk', null], ['Operational Risk', null], ['Compliance Risk', null],
                ['Geographic Risk', null], ['Supply Risk', null], ['ESG Risk', null],
            ],
            'quality' => [
                ['Quality certifications', null], ['Inspection systems', null], ['PPAP capability', null], ['Incoming and outgoing QC procedures', null],
                ['CAPA process', null], ['Rejection history', null], ['IATF 16949', null], ['PPAP', null], ['APQP', null], ['FMEA capability', null],
            ],
            'site_audit' => [
                ['Manufacturing capability', null], ['Production capacity', 'supplier_profiles.production_capacity'], ['Machinery condition', null],
                ['Warehouse management', null], ['Calibration systems', null], ['Employee competency', null], ['Quality systems', null], ['Outputs', null],
            ],
            'documents' => [
                ['ISO certificates', null], ['Insurance policies', null], ['NDAs', null], ['Contracts', null], ['Quality certificates', null],
                ['Test reports', null], ['Features', null],
            ],
            'sanctions' => [
                ['UN sanctions lists', null], ['OFAC sanctions', null], ['Politically Exposed Persons (PEP)', null], ['Anti-money laundering databases', null],
            ],
            'performance' => [
                ['On-time delivery %', null], ['Quality rejection %', null], ['Cost competitiveness', null], ['Response time', null], ['Service level', null],
            ],
            'avl' => [
                ['Category-wise supplier approval', null], ['Approved brands', null], ['Product-specific approvals', null],
                ['Supplier status tracking', null], ['Suspension and reactivation', null],
            ],
        ];
    }

    public static function key(string $heading): string
    {
        return str_replace('-', '_', make_slug($heading, 90));
    }

    /**
     * Inserts missing sheet fields and confirms/activates existing ones with
     * the same key. Never deletes rows; fields not in the sheet are only
     * deactivated (their stored values stay readable).
     */
    public static function sync(\CodeIgniter\Database\BaseConnection $db): array
    {
        helper('app');
        $now    = date('Y-m-d H:i:s');
        $sort   = 0;
        $keys   = [];
        $stats  = ['inserted' => 0, 'updated' => 0, 'deactivated' => 0];
        // Read the live schema: fieldExists() caches column names per connection,
        // which is stale when the column was added earlier in the same migrate run.
        $hasActive = in_array('is_active', array_column($db->query('SHOW COLUMNS FROM supplier_master_fields')->getResultArray(), 'Field'), true);
        foreach (self::fields() as $group => $fields) {
            foreach ($fields as [$heading, $target]) {
                $key    = self::key($heading);
                $keys[] = $key;
                $row    = ['source_heading' => $heading, 'field_group' => $group, 'target' => $target, 'sort_order' => $sort++, 'is_confirmed' => 1, 'updated_at' => $now];
                if ($hasActive) {
                    $row['is_active'] = 1;
                }
                if ($db->table('supplier_master_fields')->where('field_key', $key)->countAllResults() > 0) {
                    $db->table('supplier_master_fields')->where('field_key', $key)->update($row);
                    $stats['updated']++;
                } else {
                    $db->table('supplier_master_fields')->insert($row + ['field_key' => $key, 'data_type' => 'string', 'created_at' => $now]);
                    $stats['inserted']++;
                }
            }
        }
        if ($hasActive) {
            $db->table('supplier_master_fields')->whereNotIn('field_key', $keys)->where('is_confirmed', 0)->update(['is_active' => 0, 'sort_order' => 1000, 'updated_at' => $now]);
            $stats['deactivated'] = $db->affectedRows();
        }

        return $stats;
    }

    public function run(): void
    {
        self::sync($this->db);
    }
}
