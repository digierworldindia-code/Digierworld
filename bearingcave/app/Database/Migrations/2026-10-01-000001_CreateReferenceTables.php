<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\SchemaHelper;
use CodeIgniter\Database\Migration;

/**
 * Reference data and platform-wide configuration.
 */
class CreateReferenceTables extends Migration
{
    use SchemaHelper;

    public function up(): void
    {
        $this->table('countries', [
            'iso2'       => ['type' => 'CHAR', 'constraint' => 2],
            'name'       => $this->str(120),
            'region'     => $this->str(60, true),
            'is_enabled' => $this->bool(1),
        ], [], ['iso2']);

        $this->table('currencies', [
            'code'         => ['type' => 'CHAR', 'constraint' => 3],
            'name'         => $this->str(80),
            'symbol'       => $this->str(8),
            'is_enabled'   => $this->bool(1),
            'is_base'      => $this->bool(0),
            // Manual reference rate to the base currency. NOT a live FX feed.
            'rate_to_base' => $this->dec(18, 6, true),
            'rate_updated_at' => $this->dt(),
        ], [], ['code']);

        // Central business-policy store (see App\Services\SettingsService).
        $this->table('platform_settings', [
            'setting_key'     => $this->str(120),
            'setting_value'   => $this->text(),
            'value_type'      => $this->enum(['string', 'int', 'decimal', 'bool', 'json', 'text'], 'string'),
            'setting_group'   => $this->str(60),
            'label'           => $this->str(191),
            'description'     => $this->text(),
            'approval_status' => $this->enum(['approved', 'pending_approval'], 'pending_approval'),
            'is_sensitive'    => $this->bool(0),
            'updated_by'      => $this->ref(true),
        ] + $this->timestamps(), ['setting_group'], ['setting_key']);
    }

    public function down(): void
    {
        $this->forge->dropTable('platform_settings', true);
        $this->forge->dropTable('currencies', true);
        $this->forge->dropTable('countries', true);
    }
}
