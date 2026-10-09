<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `inventory_movements` table.
 */
class InventoryMovementModel extends Model
{
    protected $table          = 'inventory_movements';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = '';
    protected $allowedFields  = [
        'product_id',
        'lot_id',
        'movement_type',
        'quantity',
        'on_hand_after',
        'reserved_after',
        'reference_type',
        'reference_id',
        'user_id',
        'note',
        'created_at',
    ];
}
