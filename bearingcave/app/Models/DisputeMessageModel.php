<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `dispute_messages` table.
 */
class DisputeMessageModel extends Model
{
    protected $table          = 'dispute_messages';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = '';
    protected $allowedFields  = [
        'dispute_id',
        'user_id',
        'sender_side',
        'message',
        'is_internal',
        'created_at',
    ];
}
