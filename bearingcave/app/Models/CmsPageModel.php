<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `cms_pages` table.
 */
class CmsPageModel extends Model
{
    protected $table          = 'cms_pages';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'slug',
        'title',
        'body',
        'meta_title',
        'meta_description',
        'status',
        'show_in_footer',
        'approval_status',
        'updated_by',
    ];
}
