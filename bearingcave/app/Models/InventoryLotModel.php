<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `inventory_lots` table.
 */
class InventoryLotModel extends Model
{
    protected $table          = 'inventory_lots';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'product_id',
        'lot_code',
        'quantity',
        'received_on',
        'manufactured_on',
        'warehouse_location',
        'notes',
    ];
}
