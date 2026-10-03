<?php

namespace App\Database\Seeds;

use App\Database\SqlScript;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

/**
 * Roles, permissions, default permission matrix, report types, shifts, units,
 * inspection methods, gauge types and default settings.
 *
 * Source: database/schema/qms_reference_data.sql (the reviewed reference file).
 * Safe to run twice: it does nothing when the roles table already has rows.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        if ($this->db->table('roles')->countAllResults() > 0) {
            $this->say('Reference data already present - skipped.');

            return;
        }

        $count = SqlScript::run($this->db, ROOTPATH . 'database/schema/qms_reference_data.sql');
        $this->say("Reference data loaded ({$count} statements).");
    }

    private function say(string $message): void
    {
        if (is_cli() && ENVIRONMENT !== 'testing') {
            CLI::write($message);
        }
    }
}
