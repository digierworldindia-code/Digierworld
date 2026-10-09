<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `saved_products` table.
 */
class SavedProductModel extends Model
{
    protected $table          = 'saved_products';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = '';
    protected $allowedFields  = [
        'user_id',
        'product_id',
        'created_at',
    ];
}
