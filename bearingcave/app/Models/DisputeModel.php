<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `disputes` table.
 */
class DisputeModel extends Model
{
    protected $table          = 'disputes';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'dispute_number',
        'order_id',
        'raised_by_company_id',
        'against_company_id',
        'raised_by',
        'category',
        'subject',
        'description',
        'desired_resolution',
        'status',
        'assigned_to',
        'resolution_notes',
        'resolved_at',
        'closed_at',
    ];
}
