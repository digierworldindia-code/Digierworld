<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `payments` table.
 */
class PaymentModel extends Model
{
    protected $table          = 'payments';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'company_id',
        'invoice_id',
        'order_id',
        'provider',
        'provider_reference',
        'idempotency_key',
        'method',
        'amount',
        'currency',
        'status',
        'is_sandbox',
        'is_escrow',
        'escrow_status',
        'recorded_by',
        'paid_at',
        'notes',
        'raw_payload',
    ];
}
