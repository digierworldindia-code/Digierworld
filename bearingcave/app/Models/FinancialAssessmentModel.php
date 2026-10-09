<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `financial_assessments` table.
 */
class FinancialAssessmentModel extends Model
{
    protected $table          = 'financial_assessments';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'company_id',
        'fiscal_year',
        'currency',
        'annual_turnover',
        'net_profit',
        'total_assets',
        'total_liabilities',
        'credit_rating',
        'credit_agency',
        'banking_reference',
        'legal_cases_count',
        'legal_case_notes',
        'bankruptcy_history',
        'status',
        'reviewer_notes',
        'reviewed_by',
        'reviewed_at',
    ];
}
