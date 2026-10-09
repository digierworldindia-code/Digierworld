<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `platform_settings` table.
 */
class PlatformSettingModel extends Model
{
    protected $table          = 'platform_settings';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'setting_key',
        'setting_value',
        'value_type',
        'setting_group',
        'label',
        'description',
        'approval_status',
        'is_sensitive',
        'updated_by',
    ];
}
