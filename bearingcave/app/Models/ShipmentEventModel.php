<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `shipment_events` table.
 */
class ShipmentEventModel extends Model
{
    protected $table          = 'shipment_events';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = '';
    protected $allowedFields  = [
        'shipment_id',
        'status',
        'location',
        'description',
        'event_at',
        'created_by',
        'created_at',
    ];
}
