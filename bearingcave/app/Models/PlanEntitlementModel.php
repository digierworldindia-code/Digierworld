<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `plan_entitlements` table.
 */
class PlanEntitlementModel extends Model
{
    protected $table          = 'plan_entitlements';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'plan_id',
        'feature_key',
        'is_enabled',
        'limit_value',
    ];
}
