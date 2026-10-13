<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\SchemaHelper;
use CodeIgniter\Database\Migration;

/**
 * Additive, reversible columns needed by the requirements in the client's
 * BearingCave workbook (supplied 2026-10-13). Nothing is dropped or renamed;
 * every new column has a default so existing rows stay valid.
 */
class AddSheetAlignmentColumns extends Migration
{
    use SchemaHelper;

    /** @var array<string, array<string, array>> */
    private function columns(): array
    {
        return [
            // Supplier List tab: retire unconfirmed proposals without deleting them.
            'supplier_master_fields' => ['is_active' => $this->bool(1)],
            // "Direct enquiry submission ... Not verified supplier: No" → enquiries routed via BearingCave.
            'product_enquiries' => [
                'routing'      => $this->enum(['direct', 'platform'], 'direct'),
                'forwarded_at' => $this->dt(),
                'forwarded_by' => $this->ref(true),
            ],
            // "Inventory liquidation tools for surplus stock clearance".
            'products' => [
                'clearance_pct'             => $this->dec(5, 2, true),
                'clearance_started_at'      => $this->dt(),
                'pre_clearance_unit_price'  => $this->dec(15, 4, true),
                'pre_clearance_lot_price'   => $this->dec(15, 2, true),
            ],
            // Supplier score "based on transaction history, reliability, and compliance".
            'supplier_performance' => ['compliance_score' => $this->dec(5, 2, true)],
            // Client score: bonus for BearingCave logistics/inspection use; overall score.
            'buyer_profiles' => [
                'services_bonus_score' => $this->dec(5, 2, true),
                'overall_score'        => $this->dec(5, 2, true),
            ],
            // "Bearing Cave intervention applies when inspection services are opted for".
            'disputes' => [
                'bc_intervention_eligible' => $this->bool(0),
                'inspection_request_id'    => $this->ref(true),
            ],
            // Checkout option: inspection requested before purchase confirmation.
            'inspection_requests' => ['requested_at_checkout' => $this->bool(0)],
        ];
    }

    /**
     * Live schema check. BaseConnection::fieldExists() caches column names per
     * connection, which is stale after a rollback/re-run in the same process.
     */
    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $this->db->escape($column))->getNumRows() > 0;
    }

    public function up(): void
    {
        foreach ($this->columns() as $table => $fields) {
            $missing = array_filter($fields, fn ($f, $name) => ! $this->hasColumn($table, $name), ARRAY_FILTER_USE_BOTH);
            if ($missing) {
                $this->forge->addColumn($table, $missing);
            }
        }
        $this->addIndex('products', 'idx_products_clearance', '(clearance_started_at)');
        $this->addIndex('product_enquiries', 'idx_enquiries_routing', '(routing, status)');
    }

    private function hasIndex(string $table, string $index): bool
    {
        return $this->db->query('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ' . $this->db->escape($index))->getNumRows() > 0;
    }

    private function addIndex(string $table, string $index, string $columns): void
    {
        if (! $this->hasIndex($table, $index)) {
            $this->db->query("CREATE INDEX {$index} ON {$table} {$columns}");
        }
    }

    public function down(): void
    {
        foreach (['product_enquiries' => 'idx_enquiries_routing', 'products' => 'idx_products_clearance'] as $table => $index) {
            if ($this->hasIndex($table, $index)) {
                $this->db->query("DROP INDEX {$index} ON {$table}");
            }
        }
        foreach ($this->columns() as $table => $fields) {
            foreach (array_keys($fields) as $name) {
                if ($this->hasColumn($table, $name)) {
                    $this->forge->dropColumn($table, $name);
                }
            }
        }
    }
}
