<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\MigrationRunner;
use Config\Database;
use Config\Migrations;
use Throwable;

/**
 * Applies pending migrations as the schema owner.
 *
 *   php spark polyfix:migrate
 *   POLYFIX_OWNER_PASSWORD=... php spark polyfix:migrate          (automation)
 *   php spark polyfix:migrate --owner-user dba_account
 *
 * Why not `php spark migrate`: the web application's database user cannot run
 * DDL, by design, and the stock command does its bookkeeping on that default
 * connection. This command builds the runner around a connection made with the
 * owner's credentials, which then carries through to every migration.
 *
 * The owner password is read at run time — from POLYFIX_OWNER_PASSWORD or a
 * hidden prompt — and never written to .env or any config file.
 */
class Migrate extends BaseCommand
{
    protected $group       = 'POLYFIX';
    protected $name        = 'polyfix:migrate';
    protected $description = 'Apply pending database migrations as the schema owner.';
    protected $usage       = 'polyfix:migrate [--owner-user <name>] [--group <name>]';
    protected $options     = [
        '--owner-user' => 'MySQL account with DDL rights (default: polyfix_owner)',
        '--group'      => 'Database group to migrate (default: the default group; "tests" for the test database)',
    ];

    public function run(array $params)
    {
        $user     = $params['owner-user'] ?? CLI::getOption('owner-user') ?? 'polyfix_owner';
        $password = getenv('POLYFIX_OWNER_PASSWORD') ?: $this->promptHidden("Password for MySQL user {$user}: ");

        if ($password === '') {
            CLI::error('No password supplied. Set POLYFIX_OWNER_PASSWORD or enter it at the prompt.');

            return EXIT_ERROR;
        }

        $group      = $params['group'] ?? CLI::getOption('group') ?? 'default';
        $config     = config(Database::class);
        $connection = $config->{$group} ?? null;
        if ($connection === null) {
            CLI::error("No database group named {$group}.");

            return EXIT_ERROR;
        }
        $connection['username'] = $user;
        $connection['password'] = $password;

        CLI::write("Applying migrations to `{$connection['database']}` as {$user}…", 'yellow');

        try {
            $runner = new MigrationRunner(config(Migrations::class), $connection);
            $runner->setNamespace(null);
            $ok = $runner->latest();

            foreach ($runner->getCliMessages() as $message) {
                CLI::write($message);
            }
        } catch (Throwable $e) {
            // The message names the problem; it never contains the password.
            CLI::error('Migration failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        if (! $ok) {
            CLI::error('Migration did not complete. Nothing after the failing step was applied.');

            return EXIT_ERROR;
        }

        CLI::write('Done. Now apply least-privilege grants: see docs/database.md.', 'green');

        return EXIT_SUCCESS;
    }

    private function promptHidden(string $label): string
    {
        if (! CLI::isInteractive()) {
            return '';
        }

        CLI::print($label);
        $stty = shell_exec('stty -g 2>/dev/null');
        if ($stty !== null && $stty !== false) {
            shell_exec('stty -echo 2>/dev/null');
        }
        $value = trim((string) fgets(STDIN));
        if ($stty !== null && $stty !== false) {
            shell_exec('stty ' . escapeshellarg(trim($stty)) . ' 2>/dev/null');
        }
        CLI::newLine();

        return $value;
    }
}
