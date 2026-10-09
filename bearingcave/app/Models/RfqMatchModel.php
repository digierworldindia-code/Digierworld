<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `rfq_matches` table.
 */
class RfqMatchModel extends Model
{
    protected $table          = 'rfq_matches';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'rfq_id',
        'supplier_company_id',
        'match_score',
        'match_reasons',
        'matched_product_ids',
        'status',
        'invited_by',
        'invited_at',
        'viewed_at',
        'responded_at',
        'decline_reason',
    ];
}
