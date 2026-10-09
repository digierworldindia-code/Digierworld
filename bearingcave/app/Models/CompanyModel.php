<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `companies` table.
 */
class CompanyModel extends Model
{
    protected $table          = 'companies';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'uuid',
        'company_type',
        'legal_name',
        'trade_name',
        'public_alias',
        'slug',
        'registration_number',
        'business_type',
        'year_established',
        'employee_count',
        'country_code',
        'state',
        'city',
        'address_line1',
        'address_line2',
        'postal_code',
        'phone',
        'email',
        'website',
        'description',
        'logo_path',
        'verification_status',
        'verified_at',
        'status',
        'suspension_reason',
        'public_profile_enabled',
        'show_contact_public',
        'account_manager_user_id',
        'is_sample',
    ];
}
