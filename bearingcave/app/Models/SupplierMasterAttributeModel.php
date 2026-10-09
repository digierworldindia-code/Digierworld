<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `supplier_master_attributes` table.
 */
class SupplierMasterAttributeModel extends Model
{
    protected $table          = 'supplier_master_attributes';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'company_id',
        'field_key',
        'value',
        'import_job_id',
    ];
}
