<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `order_status_history` table.
 */
class OrderStatusHistoryModel extends Model
{
    protected $table          = 'order_status_history';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = '';
    protected $allowedFields  = [
        'order_id',
        'from_status',
        'to_status',
        'user_id',
        'note',
        'created_at',
    ];
}
