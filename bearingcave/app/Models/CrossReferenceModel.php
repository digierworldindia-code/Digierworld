<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `cross_references` table.
 */
class CrossReferenceModel extends Model
{
    protected $table          = 'cross_references';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'product_id',
        'ref_brand',
        'ref_part_number',
        'ref_part_number_norm',
        'relation',
        'verified_by',
        'verified_at',
        'notes',
    ];
}
