<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `orders` table.
 */
class OrderModel extends Model
{
    protected $table          = 'orders';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'order_number',
        'buyer_company_id',
        'supplier_company_id',
        'quotation_id',
        'rfq_id',
        'source',
        'status',
        'payment_status',
        'shipment_status',
        'currency',
        'subtotal',
        'inspection_fee',
        'logistics_fee',
        'tax_amount',
        'total',
        'incoterm',
        'payment_terms',
        'delivery_country',
        'delivery_address',
        'logistics_mode',
        'commercial_terms',
        'buyer_po_reference',
        'buyer_confirmed_at',
        'supplier_confirmed_at',
        'cancelled_reason',
        'created_by',
    ];
}
