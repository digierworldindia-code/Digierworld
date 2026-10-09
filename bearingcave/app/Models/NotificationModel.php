<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `notifications` table.
 */
class NotificationModel extends Model
{
    protected $table          = 'notifications';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = '';
    protected $allowedFields  = [
        'user_id',
        'type',
        'title',
        'body',
        'link',
        'read_at',
        'created_at',
    ];
}
