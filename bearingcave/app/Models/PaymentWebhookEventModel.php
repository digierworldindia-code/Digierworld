<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `payment_webhook_events` table.
 */
class PaymentWebhookEventModel extends Model
{
    protected $table          = 'payment_webhook_events';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'provider',
        'event_id',
        'event_type',
        'payload',
        'signature_valid',
        'status',
        'error',
        'processed_at',
    ];
}
