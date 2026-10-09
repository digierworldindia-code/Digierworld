<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `product_enquiries` table.
 */
class ProductEnquiryModel extends Model
{
    protected $table          = 'product_enquiries';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'product_id',
        'buyer_company_id',
        'user_id',
        'quantity',
        'message',
        'status',
        'response',
        'responded_by',
        'responded_at',
    ];
}
