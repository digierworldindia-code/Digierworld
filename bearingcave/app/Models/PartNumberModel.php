<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `part_numbers` table.
 */
class PartNumberModel extends Model
{
    protected $table          = 'part_numbers';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'product_id',
        'number_type',
        'part_number',
        'part_number_norm',
        'brand_name',
    ];
}
