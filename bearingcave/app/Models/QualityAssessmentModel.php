<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `quality_assessments` table.
 */
class QualityAssessmentModel extends Model
{
    protected $table          = 'quality_assessments';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'company_id',
        'has_inspection_system',
        'ppap',
        'apqp',
        'fmea',
        'capa',
        'qc_process_notes',
        'rejection_rate_pct',
        'quality_certificates',
        'score',
        'status',
        'reviewer_notes',
        'reviewed_by',
        'reviewed_at',
    ];
}
