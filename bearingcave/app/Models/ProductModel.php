<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `products` table.
 */
class ProductModel extends Model
{
    protected $table          = 'products';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'uuid',
        'company_id',
        'category_id',
        'brand_id',
        'sku',
        'name',
        'slug',
        'part_number',
        'part_number_norm',
        'oem_part_number',
        'oem_part_number_norm',
        'description',
        'search_keywords',
        'item_condition',
        'unit_of_measure',
        'length_mm',
        'width_mm',
        'height_mm',
        'inner_diameter_mm',
        'outer_diameter_mm',
        'weight_kg',
        'currency',
        'sale_mode',
        'unit_price',
        'lot_price',
        'lot_quantity',
        'moq',
        'price_visibility',
        'stock_on_hand',
        'stock_reserved',
        'inventory_age_date',
        'warehouse_city',
        'warehouse_country',
        'visibility',
        'country_mode',
        'hide_supplier_identity',
        'shipping_options',
        'inspection_status',
        'status',
        'review_notes',
        'approved_by',
        'approved_at',
        'published_at',
        'view_count',
        'is_sample',
    ];
}
