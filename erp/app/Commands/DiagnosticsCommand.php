<?php

namespace App\Commands;

use App\Services\Admin\Diagnostics;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** php spark dgr:diagnostics — the Admin › Diagnostics checks on the command line (exit 1 on any failure). */
class DiagnosticsCommand extends BaseCommand
{
    protected $group       = 'DGR Office';
    protected $name        = 'dgr:diagnostics';
    protected $description = 'Check PHP, extensions, dependencies, database, migrations, folders, cron, integrations, backups and security settings.';

    public function run(array $params)
    {
        $rows = (new Diagnostics(db_connect()))->run();
        $fail = 0;
        $section = '';
        foreach ($rows as [$sec, $label, $status, $detail]) {
            if ($sec !== $section) {
                CLI::newLine();
                CLI::write($sec, 'yellow');
                $section = $sec;
            }
            $color = ['ok' => 'green', 'warn' => 'yellow', 'fail' => 'red'][$status] ?? 'white';
            CLI::write('  ' . CLI::color(str_pad(strtoupper($status), 5), $color) . ' ' . str_pad($label, 36) . ' ' . $detail);
            $fail += $status === 'fail' ? 1 : 0;
        }
        CLI::newLine();
        CLI::write($fail === 0 ? 'No blocking problems found.' : $fail . ' check(s) failed.', $fail === 0 ? 'green' : 'red');

        return $fail === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
