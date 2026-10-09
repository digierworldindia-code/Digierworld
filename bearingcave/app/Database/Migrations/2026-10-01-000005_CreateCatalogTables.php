<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\SchemaHelper;
use CodeIgniter\Database\Migration;

/**
 * Catalog, inventory, pricing, country visibility, enquiries and imports.
 */
class CreateCatalogTables extends Migration
{
    use SchemaHelper;

    public function up(): void
    {
        $this->table('categories', [
            'parent_id'   => $this->ref(true),
            'name'        => $this->str(120),
            'slug'        => $this->str(140),
            'description' => $this->text(),
            'icon'        => $this->str(60, true),
            'sort_order'  => $this->int(),
            'is_active'   => $this->bool(1),
            'meta_title'  => $this->str(191, true),
            'meta_description' => $this->str(255, true),
        ] + $this->timestamps(), ['parent_id'], ['slug'], [['parent_id', 'categories', 'SET NULL']]);

        $this->table('brands', [
            'name'         => $this->str(120),
            'slug'         => $this->str(140),
            'country_code' => ['type' => 'CHAR', 'constraint' => 2, 'null' => true],
            'brand_type'   => $this->enum(['oem', 'aftermarket', 'both'], 'both'),
            'logo_path'    => $this->str(191, true),
            'is_active'    => $this->bool(1),
        ] + $this->timestamps(), [], ['slug']);

        $this->table('products', [
            'uuid'                 => ['type' => 'CHAR', 'constraint' => 36],
            'company_id'           => $this->ref(),
            'category_id'          => $this->ref(),
            'brand_id'             => $this->ref(true),
            'sku'                  => $this->str(80),
            'name'                 => $this->str(191),
            'slug'                 => $this->str(220),
            'part_number'          => $this->str(80),
            'part_number_norm'     => $this->str(80),
            'oem_part_number'      => $this->str(80, true),
            'oem_part_number_norm' => $this->str(80, true),
            'description'          => $this->text(),
            'search_keywords'      => $this->text(),
            'item_condition'       => $this->enum(['new', 'new_old_stock', 'new_open_box', 'refurbished', 'used'], 'new'),
            'unit_of_measure'      => $this->str(20, false, 'pcs'),
            'length_mm'            => $this->dec(10, 2, true),
            'width_mm'             => $this->dec(10, 2, true),
            'height_mm'            => $this->dec(10, 2, true),
            'inner_diameter_mm'    => $this->dec(10, 3, true),
            'outer_diameter_mm'    => $this->dec(10, 3, true),
            'weight_kg'            => $this->dec(10, 3, true),
            'currency'             => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'INR'],
            'sale_mode'            => $this->enum(['lot', 'piece', 'both'], 'lot'),
            'unit_price'           => $this->dec(15, 4, true),
            'lot_price'            => $this->dec(15, 2, true),
            'lot_quantity'         => $this->int(true, null),
            'moq'                  => $this->int(false, 1),
            'price_visibility'     => $this->enum(['show', 'on_request'], 'show'),
            'stock_on_hand'        => $this->int(false, 0),
            'stock_reserved'       => $this->int(false, 0),
            'inventory_age_date'   => $this->date(),
            'warehouse_city'       => $this->str(100, true),
            'warehouse_country'    => ['type' => 'CHAR', 'constraint' => 2, 'null' => true],
            'visibility'           => $this->enum(['public', 'verified_buyers', 'hidden'], 'public'),
            'country_mode'         => $this->enum(['all', 'allow_list', 'deny_list'], 'all'),
            'hide_supplier_identity' => $this->bool(1),
            'shipping_options'     => $this->str(120, false, 'platform_managed'),
            'inspection_status'    => $this->enum(['none', 'requested', 'passed', 'failed'], 'none'),
            'status'               => $this->enum(['draft', 'pending_review', 'published', 'rejected', 'unpublished', 'archived'], 'draft'),
            'review_notes'         => $this->text(),
            'approved_by'          => $this->ref(true),
            'approved_at'          => $this->dt(),
            'published_at'         => $this->dt(),
            'view_count'           => $this->int(),
            'is_sample'            => $this->bool(0),
        ] + $this->timestamps(true),
            ['part_number_norm', 'oem_part_number_norm', ['status', 'visibility'], 'category_id', 'brand_id', 'company_id', 'inventory_age_date', 'warehouse_country'],
            ['uuid', 'slug', ['company_id', 'sku']],
            [['company_id', 'companies', 'CASCADE'], ['category_id', 'categories', 'RESTRICT'], ['brand_id', 'brands', 'SET NULL'], ['approved_by', 'users', 'SET NULL']]
        );
        $this->db->query('ALTER TABLE products ADD FULLTEXT INDEX ft_products_search (name, part_number, search_keywords)');
        // Guard against negative stock or over-reservation at the database level.
        $this->db->query('ALTER TABLE products ADD CONSTRAINT chk_products_stock CHECK (stock_on_hand >= 0 AND stock_reserved >= 0 AND stock_reserved <= stock_on_hand)');

        // Additional manufacturer / OEM part numbers for a product.
        $this->table('part_numbers', [
            'product_id'  => $this->ref(),
            'number_type' => $this->enum(['manufacturer', 'oem', 'alternate', 'supersession']),
            'part_number' => $this->str(80),
            'part_number_norm' => $this->str(80),
            'brand_name'  => $this->str(120, true),
        ] + $this->timestamps(), ['part_number_norm'], [['product_id', 'number_type', 'part_number_norm']],
            [['product_id', 'products', 'CASCADE']]
        );

        // Cross references are supplier declarations unless verified.
        $this->table('cross_references', [
            'product_id'       => $this->ref(),
            'ref_brand'        => $this->str(120),
            'ref_part_number'  => $this->str(80),
            'ref_part_number_norm' => $this->str(80),
            'relation'         => $this->enum(['declared_equivalent', 'verified_interchange', 'supersedes', 'superseded_by'], 'declared_equivalent'),
            'verified_by'      => $this->ref(true),
            'verified_at'      => $this->dt(),
            'notes'            => $this->str(255, true),
        ] + $this->timestamps(), ['ref_part_number_norm'], [['product_id', 'ref_brand', 'ref_part_number_norm']],
            [['product_id', 'products', 'CASCADE'], ['verified_by', 'users', 'SET NULL']]
        );

        $this->table('fitments', [
            'product_id' => $this->ref(),
            'make'       => $this->str(80),
            'model'      => $this->str(80, true),
            'variant'    => $this->str(120, true),
            'engine'     => $this->str(80, true),
            'year_from'  => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
            'year_to'    => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
            'application' => $this->str(191, true),
        ] + $this->timestamps(), ['product_id', ['make', 'model']], [], [['product_id', 'products', 'CASCADE']]);

        $this->table('product_specifications', [
            'product_id' => $this->ref(),
            'spec_name'  => $this->str(100),
            'spec_value' => $this->str(191),
            'unit'       => $this->str(20, true),
            'sort_order' => $this->int(),
        ], ['product_id'], [], [['product_id', 'products', 'CASCADE']]);

        $this->table('product_images', [
            'product_id' => $this->ref(),
            'path'       => $this->str(255),
            'alt_text'   => $this->str(191, true),
            'sort_order' => $this->int(),
            'is_primary' => $this->bool(0),
        ] + $this->timestamps(), ['product_id'], [], [['product_id', 'products', 'CASCADE']]);

        $this->table('pricing_tiers', [
            'product_id' => $this->ref(),
            'min_qty'    => $this->int(false, 1),
            'max_qty'    => $this->int(true, null),
            'unit_price' => $this->dec(15, 4, false),
        ] + $this->timestamps(), [], [['product_id', 'min_qty']], [['product_id', 'products', 'CASCADE']]);

        $this->table('product_country_rules', [
            'product_id'   => $this->ref(),
            'country_code' => ['type' => 'CHAR', 'constraint' => 2],
            'rule'         => $this->enum(['allow', 'deny']),
        ], ['country_code'], [['product_id', 'country_code']],
            [['product_id', 'products', 'CASCADE'], ['country_code', 'countries', 'CASCADE', 'iso2']]
        );

        $this->table('inventory_lots', [
            'product_id'      => $this->ref(),
            'lot_code'        => $this->str(60),
            'quantity'        => $this->int(),
            'received_on'     => $this->date(false),
            'manufactured_on' => $this->date(),
            'warehouse_location' => $this->str(191, true),
            'notes'           => $this->str(255, true),
        ] + $this->timestamps(), ['received_on'], [['product_id', 'lot_code']], [['product_id', 'products', 'CASCADE']]);
        $this->db->query('ALTER TABLE inventory_lots ADD CONSTRAINT chk_lots_qty CHECK (quantity >= 0)');

        $this->table('inventory_movements', [
            'product_id'     => $this->ref(),
            'lot_id'         => $this->ref(true),
            'movement_type'  => $this->enum(['receipt', 'adjustment', 'reservation', 'release', 'dispatch', 'import', 'return']),
            'quantity'       => ['type' => 'INT', 'null' => false],
            'on_hand_after'  => $this->int(),
            'reserved_after' => $this->int(),
            'reference_type' => $this->str(40, true),
            'reference_id'   => $this->ref(true),
            'user_id'        => $this->ref(true),
            'note'           => $this->str(255, true),
            'created_at'     => $this->dt(),
        ], ['product_id', ['reference_type', 'reference_id']], [],
            [['product_id', 'products', 'CASCADE'], ['lot_id', 'inventory_lots', 'SET NULL'], ['user_id', 'users', 'SET NULL']]
        );

        $this->table('product_enquiries', [
            'product_id'       => $this->ref(),
            'buyer_company_id' => $this->ref(),
            'user_id'          => $this->ref(true),
            'quantity'         => $this->int(true, null),
            'message'          => $this->text(false),
            'status'           => $this->enum(['open', 'responded', 'closed', 'converted'], 'open'),
            'response'         => $this->text(),
            'responded_by'     => $this->ref(true),
            'responded_at'     => $this->dt(),
        ] + $this->timestamps(), ['status'], [],
            [['product_id', 'products', 'CASCADE'], ['buyer_company_id', 'companies', 'CASCADE'], ['user_id', 'users', 'SET NULL'], ['responded_by', 'users', 'SET NULL']]
        );

        $this->table('saved_products', [
            'user_id'    => $this->ref(),
            'product_id' => $this->ref(),
            'created_at' => $this->dt(),
        ], [], [['user_id', 'product_id']], [['user_id', 'users', 'CASCADE'], ['product_id', 'products', 'CASCADE']]);

        $this->table('search_logs', [
            'user_id'       => $this->ref(true),
            'company_id'    => $this->ref(true),
            'query'         => $this->str(191, true),
            'filters'       => $this->json(),
            'results_count' => $this->int(),
            'country_code'  => ['type' => 'CHAR', 'constraint' => 2, 'null' => true],
            'created_at'    => $this->dt(),
        ], ['created_at', 'company_id']);

        $this->table('import_jobs', [
            'uuid'          => ['type' => 'CHAR', 'constraint' => 36],
            'import_type'   => $this->enum(['products', 'supplier_master']),
            'company_id'    => $this->ref(true),
            'created_by'    => $this->ref(true),
            'original_name' => $this->str(191),
            'stored_path'   => $this->str(255),
            'status'        => $this->enum(['uploaded', 'mapped', 'validated', 'completed', 'failed', 'cancelled'], 'uploaded'),
            'headers'       => $this->json(),
            'column_map'    => $this->json(),
            'total_rows'    => $this->int(),
            'valid_rows'    => $this->int(),
            'error_rows'    => $this->int(),
            'duplicate_rows' => $this->int(),
            'imported_rows' => $this->int(),
            'error_message' => $this->text(),
            'completed_at'  => $this->dt(),
        ] + $this->timestamps(), ['status'], ['uuid'],
            [['company_id', 'companies', 'CASCADE'], ['created_by', 'users', 'SET NULL']]
        );

        $this->table('import_job_rows', [
            'import_job_id' => $this->ref(),
            'row_number'    => $this->int(),
            'data'          => $this->json(),
            'errors'        => $this->json(),
            'status'        => $this->enum(['valid', 'error', 'duplicate', 'imported', 'skipped'], 'valid'),
            'entity_id'     => $this->ref(true),
        ], [['import_job_id', 'status']], [], [['import_job_id', 'import_jobs', 'CASCADE']]);
    }

    public function down(): void
    {
        foreach (['import_job_rows', 'import_jobs', 'search_logs', 'saved_products', 'product_enquiries', 'inventory_movements', 'inventory_lots', 'product_country_rules', 'pricing_tiers', 'product_images', 'product_specifications', 'fitments', 'cross_references', 'part_numbers', 'products', 'brands', 'categories'] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}
