<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `quotations` table.
 */
class QuotationModel extends Model
{
    protected $table          = 'quotations';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'quotation_number',
        'rfq_id',
        'supplier_company_id',
        'rfq_match_id',
        'revision',
        'previous_id',
        'is_current',
        'status',
        'currency',
        'total_amount',
        'valid_until',
        'delivery_terms',
        'lead_time_days',
        'payment_terms',
        'inspection_offered',
        'logistics_option',
        'notes',
        'submitted_by',
        'submitted_at',
        'buyer_decision_at',
        'buyer_decision_note',
    ];
}
