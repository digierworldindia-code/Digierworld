<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `import_jobs` table.
 */
class ImportJobModel extends Model
{
    protected $table          = 'import_jobs';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'uuid',
        'import_type',
        'company_id',
        'created_by',
        'original_name',
        'stored_path',
        'status',
        'headers',
        'column_map',
        'total_rows',
        'valid_rows',
        'error_rows',
        'duplicate_rows',
        'imported_rows',
        'error_message',
        'completed_at',
    ];
}
