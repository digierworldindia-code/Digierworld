<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * php spark dgr:install — migrate all namespaces and seed base data.
 * Safe to re-run (migrations are tracked, seeders are idempotent).
 */
class InstallCommand extends BaseCommand
{
    protected $group       = 'DGR Office';
    protected $name        = 'dgr:install';
    protected $description = 'Run all migrations (Shield, Settings, App) and seed base master data.';

    public function run(array $params)
    {
        $db = Database::connect();
        CLI::write('Database: ' . $db->getDatabase() . ' @ ' . $db->hostname, 'yellow');
        $version = $db->query('SELECT VERSION() v')->getRow()->v ?? '?';
        CLI::write('Server version: ' . $version);

        $runner = service('migrations');
        $runner->setNamespace(null);
        if (! $runner->latest()) {
            CLI::error('Migrations failed.');

            return EXIT_ERROR;
        }
        CLI::write('Migrations complete.', 'green');

        $seeder = Database::seeder();
        $seeder->call('App\Database\Seeds\DatabaseSeeder');
        CLI::write('Base data seeded.', 'green');
        CLI::newLine();
        CLI::write('Next: create the owner account →  php spark dgr:create-owner --email you@example.com --name "Owner Name"', 'cyan');

        return EXIT_SUCCESS;
    }
}
