<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `company_directors` table.
 */
class CompanyDirectorModel extends Model
{
    protected $table          = 'company_directors';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'company_id',
        'full_name',
        'designation',
        'nationality',
        'id_document_type',
        'id_document_number_enc',
        'ownership_percent',
        'is_ubo',
        'is_pep_declared',
        'verification_status',
    ];
}
