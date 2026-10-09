<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `site_audits` table.
 */
class SiteAuditModel extends Model
{
    protected $table          = 'site_audits';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'company_id',
        'scheduled_on',
        'conducted_on',
        'auditor_user_id',
        'external_auditor',
        'site_address',
        'manufacturing_capability',
        'production_capacity',
        'machinery_condition',
        'warehouse_management',
        'calibration',
        'workforce_capability',
        'quality_processes',
        'findings',
        'result',
        'report_document_id',
    ];
}
