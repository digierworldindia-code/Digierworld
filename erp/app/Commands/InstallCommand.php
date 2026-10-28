<?php

namespace App\Commands;

use App\Services\Admin\InstallService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use RuntimeException;

/**
 * php spark dgr:install — migrate all namespaces and seed base data.
 * Safe to re-run (migrations are tracked, seeders are idempotent). On an
 * existing installation with pending database changes it first writes a
 * backup to writable/backups and reads it back to verify it; if that fails
 * nothing is changed.
 */
class InstallCommand extends BaseCommand
{
    protected $group       = 'DGR Office';
    protected $name        = 'dgr:install';
    protected $description = 'Back up and verify the database when changes are pending, then run all migrations (Shield, Settings, App) and seed base master data.';
    protected $usage       = 'dgr:install [--no-backup]';
    protected $options     = ['--no-backup' => 'Skip the automatic backup before database changes (only when you made and checked one yourself).'];

    public function run(array $params)
    {
        $db = Database::connect();
        CLI::write('Database: ' . $db->getDatabase() . ' @ ' . $db->hostname, 'yellow');
        $version = $db->query('SELECT VERSION() v')->getRow()->v ?? '?';
        CLI::write('Server version: ' . $version);

        $install = new InstallService($db);
        $pending = $install->pending();
        $backup  = CLI::getOption('no-backup') === null;
        if ($pending !== [] && $install->hasData()) {
            CLI::write(count($pending) . ' database change(s) to apply: ' . implode(', ', $pending));
            if (! $backup) {
                CLI::write('Skipping the automatic backup (--no-backup).', 'yellow');
            }
        }

        try {
            $install->run($backup, static fn (string $message) => CLI::write($message));
        } catch (RuntimeException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }
        CLI::write('Migrations complete.', 'green');
        CLI::write('Base data seeded.', 'green');
        CLI::newLine();
        if (service('users')->activeSuperAdminCount() === 0) {
            CLI::write('Next: create the owner account →  php spark dgr:create-owner --email you@example.com --name "Owner Name"', 'cyan');
            CLI::write('   (or open ' . site_url('setup') . ' in the browser)', 'cyan');
        } else {
            CLI::write('Next: check the installation →  php spark dgr:diagnostics', 'cyan');
        }

        return EXIT_SUCCESS;
    }
}
