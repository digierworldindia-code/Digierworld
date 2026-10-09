<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `brands` table.
 */
class BrandModel extends Model
{
    protected $table          = 'brands';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'name',
        'slug',
        'country_code',
        'brand_type',
        'logo_path',
        'is_active',
    ];
}
