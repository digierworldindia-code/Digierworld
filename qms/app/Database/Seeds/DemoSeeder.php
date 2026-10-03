<?php

namespace App\Database\Seeds;

use App\Database\SqlScript;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

/**
 * DEMO ONLY: sample departments, employees, machines, part, gauges, parameter
 * library and the three paper formats modelled as templates (placeholder specs).
 *
 * The templates are published in the name of the first Super Admin so a fresh
 * demo system can be used immediately. Never run this on a production database.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if ($this->db->table('parts')->countAllResults() > 0) {
            $this->say('Master data already present - demo data skipped.');

            return;
        }

        $this->call(ReferenceDataSeeder::class);
        SqlScript::run($this->db, ROOTPATH . 'database/samples/qms_demo_templates.sql');

        $admin = $this->db->table('users u')
            ->select('u.id')
            ->join('roles r', 'r.id = u.role_id')
            ->where('r.code', 'SUPER_ADMIN')
            ->orderBy('u.id')
            ->get(1)
            ->getRowArray();

        if ($admin !== null) {
            $this->db->table('inspection_templates')
                ->where('status', 'DRAFT')
                ->update([
                    'status'       => 'PUBLISHED',
                    'published_at' => gmdate('Y-m-d H:i:s'),
                    'published_by' => $admin['id'],
                    'updated_at'   => gmdate('Y-m-d H:i:s'),
                ]);
            $this->say('Demo data loaded and demo templates published.');
        } else {
            $this->say('Demo data loaded. Templates stay DRAFT until a Super Admin publishes them.');
        }
    }

    private function say(string $message): void
    {
        if (is_cli() && ENVIRONMENT !== 'testing') {
            CLI::write($message);
        }
    }
}
