<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `logistics_requests` table.
 */
class LogisticsRequestModel extends Model
{
    protected $table          = 'logistics_requests';
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
        'mode',
        'consolidation_ref',
        'pickup_country',
        'pickup_city',
        'pickup_address',
        'pickup_contact',
        'destination_country',
        'destination_city',
        'destination_address',
        'destination_contact',
        'incoterm',
        'cargo_description',
        'weight_kg',
        'volume_cbm',
        'packages',
        'goods_value',
        'currency',
        'estimated_fee',
        'quoted_fee',
        'fee_basis',
        'status',
        'assigned_to',
        'invoice_id',
        'notes',
        'admin_notes',
    ];
}
