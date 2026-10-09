<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `import_job_rows` table.
 */
class ImportJobRowModel extends Model
{
    protected $table          = 'import_job_rows';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;
    protected $createdField   = 'created_at';
    protected $updatedField   = '';
    protected $allowedFields  = [
        'import_job_id',
        'row_number',
        'data',
        'errors',
        'status',
        'entity_id',
    ];
}
