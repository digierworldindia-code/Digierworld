<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `company_member_permissions` table.
 */
class CompanyMemberPermissionModel extends Model
{
    protected $table          = 'company_member_permissions';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;
    protected $createdField   = 'created_at';
    protected $updatedField   = '';
    protected $allowedFields  = [
        'company_member_id',
        'permission_key',
    ];
}
