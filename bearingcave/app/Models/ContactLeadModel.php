<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `contact_leads` table.
 */
class ContactLeadModel extends Model
{
    protected $table          = 'contact_leads';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'name',
        'company_name',
        'email',
        'phone',
        'country_code',
        'enquiry_type',
        'message',
        'status',
        'ip_address',
    ];
}
