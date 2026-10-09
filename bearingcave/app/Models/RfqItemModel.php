<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `rfq_items` table.
 */
class RfqItemModel extends Model
{
    protected $table          = 'rfq_items';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'rfq_id',
        'product_id',
        'category_id',
        'brand_id',
        'brand_text',
        'part_number',
        'part_number_norm',
        'description',
        'specifications',
        'quantity',
        'unit',
        'target_unit_price',
    ];
}
