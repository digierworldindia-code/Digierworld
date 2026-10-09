<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `sanctions_screenings` table.
 */
class SanctionsScreeningModel extends Model
{
    protected $table          = 'sanctions_screenings';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'company_id',
        'subject_type',
        'subject_name',
        'provider',
        'lists_checked',
        'result',
        'provider_reference',
        'notes',
        'screened_by',
        'screened_at',
    ];
}
