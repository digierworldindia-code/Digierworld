<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `kyc_records` table.
 */
class KycRecordModel extends Model
{
    protected $table          = 'kyc_records';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'company_id',
        'gst_number',
        'pan_number',
        'cin_number',
        'vat_number',
        'tax_id',
        'bank_account_name',
        'bank_account_number_enc',
        'bank_account_last4',
        'bank_name',
        'bank_ifsc_swift',
        'bank_country',
        'bank_validated',
        'address_verified',
        'owner_verified',
        'ubo_verified',
        'tax_verified',
        'reviewer_notes',
        'reviewed_by',
        'reviewed_at',
    ];
}
