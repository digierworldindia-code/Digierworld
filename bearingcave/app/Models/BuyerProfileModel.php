<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `buyer_profiles` table.
 */
class BuyerProfileModel extends Model
{
    protected $table          = 'buyer_profiles';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'company_id',
        'buyer_category',
        'annual_purchase_volume',
        'interested_categories',
        'preferred_currency',
        'engagement_score',
        'rfq_genuineness_score',
        'transaction_score',
        'scores_computed_at',
        'restricted_inventory_access',
    ];
}
