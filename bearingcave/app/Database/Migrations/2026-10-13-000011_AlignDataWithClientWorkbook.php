<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Seeds\SupplierMasterFieldSeeder;
use CodeIgniter\Database\Migration;

/**
 * Data alignment with the client's BearingCave workbook (2026-10-13).
 *
 * 1. Supplier master fields: the 86 real "Suppiler list" headings become the
 *    confirmed, active field set. Earlier unconfirmed proposals are only
 *    deactivated (no rows or stored values are deleted).
 * 2. Supplier Features row "Direct enquiry submission from product pages to
 *    suppliers": Not Verified Supplier = No. The free plan's
 *    direct_enquiries entitlement is switched off; buyers' enquiries for those
 *    listings are routed through BearingCave instead.
 *
 * Fully reversible: down() reactivates the proposals and restores the
 * previous entitlement value.
 */
class AlignDataWithClientWorkbook extends Migration
{
    public function up(): void
    {
        $stats = SupplierMasterFieldSeeder::sync($this->db);
        log_message('info', 'Supplier master fields aligned with workbook: ' . json_encode($stats));

        $plan = $this->db->table('membership_plans')->where('code', 'supplier_free')->get()->getRowArray();
        if ($plan) {
            $this->db->table('plan_entitlements')->where('plan_id', $plan['id'])->where('feature_key', 'direct_enquiries')
                ->update(['is_enabled' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
        }
    }

    public function down(): void
    {
        $this->db->table('supplier_master_fields')->update(['is_active' => 1]);
        $plan = $this->db->table('membership_plans')->where('code', 'supplier_free')->get()->getRowArray();
        if ($plan) {
            $this->db->table('plan_entitlements')->where('plan_id', $plan['id'])->where('feature_key', 'direct_enquiries')->update(['is_enabled' => 1]);
        }
    }
}
