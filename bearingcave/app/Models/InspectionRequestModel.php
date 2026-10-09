<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `inspection_requests` table.
 */
class InspectionRequestModel extends Model
{
    protected $table          = 'inspection_requests';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'request_number',
        'company_id',
        'requested_by',
        'order_id',
        'product_id',
        'inspection_type',
        'scope',
        'location_country',
        'location_city',
        'invoice_value',
        'shipment_volume_cbm',
        'currency',
        'estimated_fee',
        'quoted_fee',
        'fee_basis',
        'status',
        'inspector_user_id',
        'third_party_agency',
        'scheduled_on',
        'quote_valid_until',
        'invoice_id',
        'notes',
        'admin_notes',
    ];
}
