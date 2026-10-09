<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `approved_vendors` table.
 */
class ApprovedVendorModel extends Model
{
    protected $table          = 'approved_vendors';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'company_id',
        'scope_type',
        'scope_id',
        'status',
        'valid_until',
        'notes',
        'approved_by',
        'approved_at',
    ];
}
