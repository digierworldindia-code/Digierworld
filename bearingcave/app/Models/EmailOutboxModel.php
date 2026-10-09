<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `email_outbox` table.
 */
class EmailOutboxModel extends Model
{
    protected $table          = 'email_outbox';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'user_id',
        'to_email',
        'subject',
        'body_html',
        'status',
        'attempts',
        'last_error',
        'sent_at',
    ];
}
