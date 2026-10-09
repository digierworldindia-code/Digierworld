<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `shipments` table.
 */
class ShipmentModel extends Model
{
    protected $table          = 'shipments';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'logistics_request_id',
        'order_id',
        'carrier',
        'tracking_number',
        'transport_mode',
        'status',
        'shipped_at',
        'eta',
        'delivered_at',
        'delivered_on_time',
        'created_by',
    ];
}
