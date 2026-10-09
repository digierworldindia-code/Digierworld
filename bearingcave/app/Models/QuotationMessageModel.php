<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `quotation_messages` table.
 */
class QuotationMessageModel extends Model
{
    protected $table          = 'quotation_messages';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = '';
    protected $allowedFields  = [
        'quotation_id',
        'sender_side',
        'user_id',
        'message',
        'proposed_total',
        'created_at',
    ];
}
