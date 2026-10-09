<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `product_images` table.
 */
class ProductImageModel extends Model
{
    protected $table          = 'product_images';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'product_id',
        'path',
        'alt_text',
        'sort_order',
        'is_primary',
    ];
}
