<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `subscriptions` table.
 */
class SubscriptionModel extends Model
{
    protected $table          = 'subscriptions';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'company_id',
        'plan_id',
        'status',
        'starts_on',
        'ends_on',
        'invoice_id',
        'renewal_of_id',
        'reminder_sent_at',
        'notes',
        'created_by',
    ];
}
