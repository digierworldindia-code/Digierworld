<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `currencies` table.
 */
class CurrencyModel extends Model
{
    protected $table          = 'currencies';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;
    protected $createdField   = 'created_at';
    protected $updatedField   = '';
    protected $allowedFields  = [
        'code',
        'name',
        'symbol',
        'is_enabled',
        'is_base',
        'rate_to_base',
        'rate_updated_at',
    ];
}
