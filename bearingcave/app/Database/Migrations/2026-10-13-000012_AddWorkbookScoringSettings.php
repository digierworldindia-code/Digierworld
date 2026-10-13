<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Seeds\SettingsSeeder;
use CodeIgniter\Database\Migration;

/**
 * Adds settings introduced for the workbook's client scoring rules
 * (buyer.score_weights, buyer.services_bonus_per_use). Existing settings are
 * never touched; new ones arrive as "pending approval".
 */
class AddWorkbookScoringSettings extends Migration
{
    private const KEYS = ['buyer.score_weights', 'buyer.services_bonus_per_use'];

    public function up(): void
    {
        SettingsSeeder::insertMissing($this->db);
    }

    public function down(): void
    {
        $this->db->table('platform_settings')->whereIn('setting_key', self::KEYS)->delete();
    }
}
