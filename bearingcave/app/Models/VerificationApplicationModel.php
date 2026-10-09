<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `verification_applications` table.
 */
class VerificationApplicationModel extends Model
{
    protected $table          = 'verification_applications';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'company_id',
        'application_type',
        'cycle',
        'status',
        'current_stage',
        'submitted_at',
        'decided_at',
        'decided_by',
        'decision_notes',
    ];
}
