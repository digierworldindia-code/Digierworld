<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `supplier_master_fields` table.
 */
class SupplierMasterFieldModel extends Model
{
    protected $table          = 'supplier_master_fields';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'source_heading',
        'field_key',
        'field_group',
        'target',
        'data_type',
        'sort_order',
        'is_confirmed',
    ];
}
