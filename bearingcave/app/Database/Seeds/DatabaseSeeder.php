<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Seeds reference data and configurable defaults. Contains NO business
 * records. Demo data is separate: php spark db:seed DemoSeeder
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        helper('app');
        $this->call(ReferenceDataSeeder::class);
        $this->call(SettingsSeeder::class);
        $this->call(MembershipSeeder::class);
        $this->call(CatalogSeeder::class);
        $this->call(CmsSeeder::class);
        $this->call(SupplierMasterFieldSeeder::class);
    }
}
