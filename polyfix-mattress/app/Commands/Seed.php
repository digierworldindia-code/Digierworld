<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;
use Config\Database;

/**
 * Runs a seeder against a chosen database group.
 *
 *   php spark polyfix:seed ReferenceData
 *   php spark polyfix:seed ReferenceData --group tests
 *
 * The stock `db:seed` always uses the default group, which is the wrong
 * database when setting up the test schema.
 */
class Seed extends BaseCommand
{
    protected $group       = 'POLYFIX';
    protected $name        = 'polyfix:seed';
    protected $description = 'Run a seeder against a named database group.';
    protected $usage       = 'polyfix:seed <SeederName> [--group <name>]';
    protected $arguments   = ['name' => 'Seeder class name, e.g. ReferenceData'];
    protected $options     = ['--group' => 'Database group (default: the default group)'];

    public function run(array $params)
    {
        $name  = $params[0] ?? CLI::prompt('Seeder', null, 'required');
        $group = $params['group'] ?? CLI::getOption('group') ?? 'default';

        $config = config(Database::class);
        if (! isset($config->{$group})) {
            CLI::error("No database group named {$group}.");

            return EXIT_ERROR;
        }

        $seeder = new Seeder($config, db_connect($group));
        $seeder->setSilent(false)->call($name);
        CLI::write("Seeded {$name} into `{$config->{$group}['database']}`.", 'green');

        return EXIT_SUCCESS;
    }
}
