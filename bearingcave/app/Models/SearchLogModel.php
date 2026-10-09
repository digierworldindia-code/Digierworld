<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `search_logs` table.
 */
class SearchLogModel extends Model
{
    protected $table          = 'search_logs';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = '';
    protected $allowedFields  = [
        'user_id',
        'company_id',
        'query',
        'filters',
        'results_count',
        'country_code',
        'created_at',
    ];
}
