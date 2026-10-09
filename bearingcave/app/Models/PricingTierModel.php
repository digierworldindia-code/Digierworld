<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `pricing_tiers` table.
 */
class PricingTierModel extends Model
{
    protected $table          = 'pricing_tiers';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'product_id',
        'min_qty',
        'max_qty',
        'unit_price',
    ];
}
