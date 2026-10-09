<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `supplier_profiles` table.
 */
class SupplierProfileModel extends Model
{
    protected $table          = 'supplier_profiles';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'company_id',
        'supplier_type',
        'main_categories',
        'brands_handled',
        'production_capacity',
        'warehouse_count',
        'export_markets',
        'logistics_preference',
        'default_confidential',
        'ranking_score',
        'performance_score',
        'risk_level',
    ];
}
