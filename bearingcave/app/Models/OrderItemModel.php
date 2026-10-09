<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `order_items` table.
 */
class OrderItemModel extends Model
{
    protected $table          = 'order_items';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'order_id',
        'product_id',
        'quotation_item_id',
        'description',
        'part_number',
        'quantity',
        'unit_price',
        'line_total',
        'stock_reserved',
    ];
}
