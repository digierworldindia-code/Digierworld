<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Base install seed (no demo data, no users, no company identity).
 * Run: php spark db:seed DatabaseSeeder   — or —   php spark dgr:install
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(StatesSeeder::class);
        $this->call(PermissionSeeder::class);
        $this->call(MasterDataSeeder::class);
    }
}
