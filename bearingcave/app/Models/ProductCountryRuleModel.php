<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `product_country_rules` table.
 */
class ProductCountryRuleModel extends Model
{
    protected $table          = 'product_country_rules';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;
    protected $createdField   = 'created_at';
    protected $updatedField   = '';
    protected $allowedFields  = [
        'product_id',
        'country_code',
        'rule',
    ];
}
