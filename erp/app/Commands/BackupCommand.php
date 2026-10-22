<?php

namespace App\Commands;

use App\Services\Admin\BackupService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/** php spark dgr:backup [--files] [--keep 14] — gzip SQL dump (+ private files) into writable/backups. */
class BackupCommand extends BaseCommand
{
    protected $group       = 'DGR Office';
    protected $name        = 'dgr:backup';
    protected $description = 'Back up the database (and with --files the private uploads) to writable/backups, keeping the newest N.';
    protected $usage       = 'dgr:backup [--files] [--keep 14]';

    public function run(array $params)
    {
        try {
            $service = new BackupService(db_connect());
            $names   = $service->create(CLI::getOption('files') !== null, (int) (CLI::getOption('keep') ?: 14));
            $check   = $service->verify($names[0]); // read the SQL file back: complete, every table, every row
        } catch (Throwable $e) {
            CLI::error('Backup failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }
        foreach ($names as $n) {
            CLI::write('Created writable/backups/' . $n . ' (' . number_format((int) filesize(BackupService::dir() . $n) / 1024, 1) . ' KB)', 'green');
        }
        CLI::write("Verified: {$check['tables']} tables, " . number_format($check['rows']) . ' rows.', 'green');
        service('audit')->asActor('cron')->log('backup.created', 'backup', null, null, ['files' => $names], 'Backup created from CLI');
        CLI::write('Copy backups off the server regularly, and keep the .env encryption key in a separate safe place.', 'yellow');

        return EXIT_SUCCESS;
    }
}
