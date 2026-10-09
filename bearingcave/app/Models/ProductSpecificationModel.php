<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `product_specifications` table.
 */
class ProductSpecificationModel extends Model
{
    protected $table          = 'product_specifications';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;
    protected $createdField   = 'created_at';
    protected $updatedField   = '';
    protected $allowedFields  = [
        'product_id',
        'spec_name',
        'spec_value',
        'unit',
        'sort_order',
    ];
}
