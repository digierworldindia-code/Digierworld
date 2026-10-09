<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `rfqs` table.
 */
class RfqModel extends Model
{
    protected $table          = 'rfqs';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'rfq_number',
        'buyer_company_id',
        'created_by',
        'title',
        'destination_country',
        'delivery_terms',
        'delivery_requirements',
        'required_by',
        'deadline_at',
        'currency',
        'inspection_required',
        'logistics_required',
        'comments',
        'status',
        'source',
        'admin_notes',
        'reviewed_by',
        'reviewed_at',
        'distributed_at',
        'awarded_quotation_id',
    ];
}
