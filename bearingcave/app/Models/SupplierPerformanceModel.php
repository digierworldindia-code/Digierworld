<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `supplier_performance` table.
 */
class SupplierPerformanceModel extends Model
{
    protected $table          = 'supplier_performance';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'company_id',
        'period_start',
        'period_end',
        'orders_count',
        'on_time_delivery_pct',
        'quality_rejection_pct',
        'cost_competitiveness',
        'avg_response_hours',
        'rfq_response_rate_pct',
        'service_level',
        'overall_score',
        'source',
        'notes',
        'computed_at',
    ];
}
