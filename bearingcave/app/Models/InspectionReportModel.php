<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `inspection_reports` table.
 */
class InspectionReportModel extends Model
{
    protected $table          = 'inspection_reports';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'inspection_request_id',
        'result',
        'inspected_on',
        'quantity_expected',
        'quantity_verified',
        'packaging_ok',
        'authenticity_notes',
        'summary',
        'findings',
        'document_id',
        'uploaded_by',
    ];
}
