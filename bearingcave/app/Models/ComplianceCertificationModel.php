<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `compliance_certifications` table.
 */
class ComplianceCertificationModel extends Model
{
    protected $table          = 'compliance_certifications';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'company_id',
        'cert_type',
        'cert_name',
        'cert_number',
        'issuer',
        'issued_on',
        'expires_on',
        'document_id',
        'status',
        'reviewer_notes',
        'reviewed_by',
        'reviewed_at',
    ];
}
