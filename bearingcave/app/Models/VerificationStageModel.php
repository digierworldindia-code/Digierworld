<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `verification_stages` table.
 */
class VerificationStageModel extends Model
{
    protected $table          = 'verification_stages';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'application_id',
        'stage_key',
        'sort_order',
        'status',
        'assigned_to',
        'reviewed_by',
        'reviewed_at',
        'comments',
    ];
}
