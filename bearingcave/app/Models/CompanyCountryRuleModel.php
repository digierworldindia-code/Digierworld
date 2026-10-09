<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `company_country_rules` table.
 */
class CompanyCountryRuleModel extends Model
{
    protected $table          = 'company_country_rules';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;
    protected $createdField   = 'created_at';
    protected $updatedField   = '';
    protected $allowedFields  = [
        'company_id',
        'country_code',
        'rule',
    ];
}
