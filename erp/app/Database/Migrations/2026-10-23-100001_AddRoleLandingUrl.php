<?php

namespace App\Database\Migrations;

use App\Database\SqlMigration;

/**
 * Landing page per role (where a user goes after signing in). Additive:
 * one nullable column; starter roles get their default only while empty.
 */
class AddRoleLandingUrl extends SqlMigration
{
    public function up(): void
    {
        $this->db->resetDataCache(); // column lists are cached per connection; a refresh may have just dropped this one
        if (! $this->db->fieldExists('landing_url', 'roles')) {
            $this->run(["ALTER TABLE roles ADD COLUMN landing_url VARCHAR(120) NULL COMMENT 'page opened after sign-in (\"\" = owner dashboard)' AFTER description"]);
            $this->db->resetDataCache(); // later code (the seeder) must see the new column
        }
        foreach (config('Landing')->starterDefaults as $code => $path) {
            $this->db->query('UPDATE roles SET landing_url = ? WHERE code = ? AND landing_url IS NULL', [$path, $code]);
        }
    }

    public function down(): void
    {
        $this->db->resetDataCache();
        if ($this->db->fieldExists('landing_url', 'roles')) {
            $this->run(['ALTER TABLE roles DROP COLUMN landing_url']);
        }
        $this->db->resetDataCache();
    }
}
