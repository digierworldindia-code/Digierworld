<?php

namespace App\Commands;

use App\Services\Sync\SheetSyncWorker;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Checks the Google Sheets configuration without writing anything:
 * key file, service-account e-mail, access to the configured spreadsheet(s).
 */
class SheetsTest extends BaseCommand
{
    protected $group       = 'QMS';
    protected $name        = 'sheets:test';
    protected $description = 'Tests the Google Sheets credentials and spreadsheet access (read-only).';

    public function run(array $params)
    {
        $client      = service('sheetsClient');
        $credentials = $client->describeCredentials();
        if (! $credentials['configured']) {
            CLI::error($credentials['hint']);

            return EXIT_ERROR;
        }
        CLI::write('Service account: ' . $credentials['email'], 'green');

        $settings = service('settings');
        $ids      = array_filter(array_unique([
            trim($settings->string('google.spreadsheet_id')),
            trim($settings->string('google.detail_spreadsheet_id')),
        ]));
        if ($ids === []) {
            CLI::error('No spreadsheet ID configured (Admin → Settings → Google Sheets).');

            return EXIT_ERROR;
        }
        $ok = true;
        foreach ($ids as $id) {
            try {
                $tabs  = $client->sheets($id);
                $cells = array_sum(array_map(static fn (array $t): int => $t['rows'] * $t['cols'], $tabs));
                CLI::write(sprintf('%s: "%s", %d tab(s), %s of 10,000,000 cells allocated', $id, $client->spreadsheetTitle($id), count($tabs), number_format($cells)), 'green');
            } catch (Throwable $e) {
                $ok = false;
                CLI::error($id . ': ' . SheetSyncWorker::sanitize($e));
            }
        }
        CLI::write('Sync is ' . ($settings->bool('google.sync_enabled') ? 'ON' : 'OFF (switch it on in Settings)') . '.');

        return $ok ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
