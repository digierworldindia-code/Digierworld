<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Qms;

/**
 * Sends waiting Google Sheets jobs. Run every minute from cron:
 *
 *   * * * * * www-data flock -n /run/qms/sync.lock php /var/www/qms/spark sheets:sync >> /var/log/qms/sync.log 2>&1
 */
class SheetsSync extends BaseCommand
{
    protected $group       = 'QMS';
    protected $name        = 'sheets:sync';
    protected $description = 'Processes the Google Sheets synchronisation queue (MySQL stays the system of record).';
    protected $usage       = 'sheets:sync [--limit 40] [--quiet]';
    protected $options     = [
        '--limit' => 'Maximum jobs per run (default from Config\Qms::$syncBatchSize)',
        '--quiet' => 'Print only the summary line',
    ];

    public function run(array $params)
    {
        $limit = (int) (CLI::getOption('limit') ?? config(Qms::class)->syncBatchSize);
        $quiet = CLI::getOption('quiet') !== null;
        service('audit')->setActor(null);

        $summary = service('sheetWorker')->run(max(1, min(500, $limit)), $quiet ? null : static function (string $line): void {
            CLI::write(gmdate('Y-m-d H:i:s') . ' ' . $line);
        });

        if ($summary['skipped'] !== null) {
            CLI::write('Skipped: ' . $summary['skipped'], 'yellow');

            return EXIT_SUCCESS;
        }
        CLI::write(sprintf('%s processed=%d synced=%d failed=%d', gmdate('Y-m-d H:i:s'), $summary['processed'], $summary['synced'], $summary['failed']),
            $summary['failed'] > 0 ? 'yellow' : 'green');

        return EXIT_SUCCESS;
    }
}
