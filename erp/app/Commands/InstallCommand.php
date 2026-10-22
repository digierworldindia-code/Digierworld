<?php

namespace App\Commands;

use App\Services\Admin\BackupService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\MigrationRunner;
use Config\Database;
use Throwable;

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

        $runner = service('migrations');
        $runner->setNamespace(null);
        $pending = $this->pending($runner);
        if ($pending !== [] && $this->hasData($db)) {
            CLI::write(count($pending) . ' database change(s) to apply: ' . implode(', ', $pending));
            if (CLI::getOption('no-backup') !== null) {
                CLI::write('Skipping the automatic backup (--no-backup).', 'yellow');
            } else {
                CLI::write('Backing up the database first…');
                try {
                    $backups = new BackupService($db);
                    [$name]  = $backups->create(false);
                    $check   = $backups->verify($name);
                } catch (Throwable $e) {
                    CLI::error('The backup could not be made or verified, so no database change was made: ' . $e->getMessage());

                    return EXIT_ERROR;
                }
                CLI::write("Backup verified: writable/backups/{$name} ({$check['tables']} tables, " . number_format($check['rows']) . ' rows). Keep a copy off the server.', 'green');
            }
        }
        if (! $runner->latest()) {
            CLI::error('Migrations failed.');

            return EXIT_ERROR;
        }
        CLI::write('Migrations complete.', 'green');

        $seeder = Database::seeder();
        $seeder->call('App\Database\Seeds\DatabaseSeeder');
        CLI::write('Base data seeded.', 'green');
        CLI::newLine();
        if (service('users')->activeSuperAdminCount() === 0) {
            CLI::write('Next: create the owner account →  php spark dgr:create-owner --email you@example.com --name "Owner Name"', 'cyan');
        } else {
            CLI::write('Next: check the installation →  php spark dgr:diagnostics', 'cyan');
        }

        return EXIT_SUCCESS;
    }

    /** @return list<string> migrations not yet applied (short class names) */
    private function pending(MigrationRunner $runner): array
    {
        $done = array_flip(array_map(static fn ($h) => ltrim((string) $h->class, '\\'), $runner->getHistory()));
        $out  = [];
        foreach ($runner->findMigrations() as $m) {
            if (! isset($done[ltrim((string) $m->class, '\\')])) {
                $out[] = substr((string) strrchr('\\' . $m->class, '\\'), 1);
            }
        }

        return $out;
    }

    /** An installation with business tables already in place (a fresh database needs no backup). */
    private function hasData(\CodeIgniter\Database\BaseConnection $db): bool
    {
        return $db->tableExists('company_settings') || $db->tableExists('users');
    }
}
