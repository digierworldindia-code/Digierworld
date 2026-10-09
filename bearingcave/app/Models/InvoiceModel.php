<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `invoices` table.
 */
class InvoiceModel extends Model
{
    protected $table          = 'invoices';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'invoice_number',
        'company_id',
        'invoice_type',
        'reference_type',
        'reference_id',
        'currency',
        'subtotal',
        'tax_rate',
        'tax_amount',
        'total',
        'tax_note',
        'status',
        'issued_at',
        'due_at',
        'paid_at',
        'notes',
        'created_by',
    ];
}
