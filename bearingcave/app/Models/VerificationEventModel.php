<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `verification_events` table.
 */
class VerificationEventModel extends Model
{
    protected $table          = 'verification_events';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = '';
    protected $allowedFields  = [
        'application_id',
        'stage_key',
        'action',
        'user_id',
        'notes',
        'created_at',
    ];
}
