<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `documents` table.
 */
class DocumentModel extends Model
{
    protected $table          = 'documents';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'uuid',
        'company_id',
        'uploaded_by',
        'entity_type',
        'entity_id',
        'doc_type',
        'title',
        'original_name',
        'stored_path',
        'mime_type',
        'size_bytes',
        'sha256',
        'version',
        'previous_version_id',
        'is_current',
        'issued_on',
        'expires_on',
        'expiry_reminder_sent_at',
        'visibility',
        'review_status',
        'review_comment',
        'reviewed_by',
        'reviewed_at',
    ];
}
