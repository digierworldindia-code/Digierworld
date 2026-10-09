<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `risk_assessments` table.
 */
class RiskAssessmentModel extends Model
{
    protected $table          = 'risk_assessments';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'company_id',
        'application_id',
        'financial_risk',
        'operational_risk',
        'compliance_risk',
        'geographic_risk',
        'supply_risk',
        'esg_risk',
        'weighted_score',
        'risk_level',
        'evidence_notes',
        'assessed_by',
        'assessed_at',
    ];
}
