<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `fitments` table.
 */
class FitmentModel extends Model
{
    protected $table          = 'fitments';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'product_id',
        'make',
        'model',
        'variant',
        'engine',
        'year_from',
        'year_to',
        'application',
    ];
}
