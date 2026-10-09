<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `quotation_items` table.
 */
class QuotationItemModel extends Model
{
    protected $table          = 'quotation_items';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'quotation_id',
        'rfq_item_id',
        'product_id',
        'description',
        'part_number',
        'quantity',
        'unit_price',
        'line_total',
        'lead_time_days',
        'notes',
    ];
}
