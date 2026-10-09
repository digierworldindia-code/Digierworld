<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `membership_plans` table.
 */
class MembershipPlanModel extends Model
{
    protected $table          = 'membership_plans';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'code',
        'name',
        'audience',
        'annual_fee',
        'currency',
        'duration_months',
        'requires_verification',
        'is_default',
        'is_active',
        'description',
        'sort_order',
        'approval_status',
    ];
}
