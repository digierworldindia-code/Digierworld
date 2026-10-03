<?php

namespace App\Libraries\GoogleSheets;

use Config\Google as GoogleConfig;
use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\ValueRange;
use GuzzleHttp\Client as HttpClient;
use RuntimeException;

/**
 * Google Sheets API v4 through a service account.
 *
 * - Credentials: only the PATH comes from configuration (google.credentialsFile);
 *   the key file lives outside the project, readable by the PHP user only.
 * - Scope: spreadsheets only. The account can open only sheets shared with it.
 * - Every write uses valueInputOption=RAW: text such as "=HYPERLINK(...)" is stored
 *   as text, never evaluated as a formula.
 */
class GoogleSheetsClient implements SheetsClientInterface
{
    private ?Sheets $service = null;

    public function __construct(private readonly GoogleConfig $config)
    {
    }

    public function describeCredentials(): array
    {
        $file = $this->config->credentialsFile;
        if ($file === '') {
            return ['configured' => false, 'email' => null,
                'hint' => 'Set google.credentialsFile in the server .env to the path of the service-account key (outside the web root).'];
        }
        if (! is_file($file) || ! is_readable($file)) {
            return ['configured' => false, 'email' => null,
                'hint' => 'The key file is missing or not readable by the web server user. Check the path and permissions (0440, group of the PHP user).'];
        }
        $json = json_decode((string) file_get_contents($file), true);
        if (! is_array($json) || ($json['type'] ?? '') !== 'service_account' || ! isset($json['client_email'])) {
            return ['configured' => false, 'email' => null, 'hint' => 'The key file is not a Google service-account JSON key.'];
        }

        return ['configured' => true, 'email' => (string) $json['client_email'],
            'hint' => 'Share the spreadsheet (and the detail spreadsheet, if used) with this e-mail as Editor. Do not share it with anyone else as Editor.'];
    }

    public function spreadsheetTitle(string $spreadsheetId): string
    {
        $spreadsheet = $this->api()->spreadsheets->get($spreadsheetId, ['fields' => 'properties.title']);

        return (string) $spreadsheet->getProperties()->getTitle();
    }

    public function sheets(string $spreadsheetId): array
    {
        $spreadsheet = $this->api()->spreadsheets->get($spreadsheetId, ['fields' => 'sheets.properties']);
        $out         = [];
        foreach ($spreadsheet->getSheets() as $sheet) {
            $props                      = $sheet->getProperties();
            $grid                       = $props->getGridProperties();
            $out[(string) $props->getTitle()] = [
                'sheetId' => (int) $props->getSheetId(),
                'rows'    => (int) ($grid?->getRowCount() ?? 0),
                'cols'    => (int) ($grid?->getColumnCount() ?? 0),
            ];
        }

        return $out;
    }

    public function addSheet(string $spreadsheetId, string $title, array $headers): void
    {
        $api      = $this->api();
        $response = $api->spreadsheets->batchUpdate($spreadsheetId, new BatchUpdateSpreadsheetRequest(['requests' => [[
            'addSheet' => ['properties' => [
                'title'          => $title,
                'gridProperties' => ['frozenRowCount' => 1, 'columnCount' => max(26, count($headers)), 'rowCount' => 1000],
            ]],
        ]]]));
        $sheetId = (int) $response->getReplies()[0]->getAddSheet()->getProperties()->getSheetId();

        $api->spreadsheets_values->update($spreadsheetId, $this->a1($title, 'A1'), new ValueRange(['values' => [$headers]]), ['valueInputOption' => 'RAW']);

        $email = $this->describeCredentials()['email'];
        if ($email !== null) {
            $api->spreadsheets->batchUpdate($spreadsheetId, new BatchUpdateSpreadsheetRequest(['requests' => [[
                'addProtectedRange' => ['protectedRange' => [
                    'range'       => ['sheetId' => $sheetId],
                    'description' => 'Managed by QMS - edit in a separate tab',
                    'warningOnly' => false,
                    'editors'     => ['users' => [$email]],
                ]],
            ]]]));
        }
    }

    public function readKeyColumn(string $spreadsheetId, string $sheet): array
    {
        $response = $this->api()->spreadsheets_values->get($spreadsheetId, $this->a1($sheet, 'A:A'), ['majorDimension' => 'COLUMNS']);
        $values   = $response->getValues()[0] ?? [];

        return array_map('strval', $values);
    }

    public function updateRow(string $spreadsheetId, string $sheet, int $row, array $values): string
    {
        $response = $this->api()->spreadsheets_values->update(
            $spreadsheetId,
            $this->a1($sheet, 'A' . $row),
            new ValueRange(['values' => [$this->clean($values)]]),
            ['valueInputOption' => 'RAW'],
        );

        return (string) $response->getUpdatedRange();
    }

    public function appendRows(string $spreadsheetId, string $sheet, array $rows): string
    {
        $response = $this->api()->spreadsheets_values->append(
            $spreadsheetId,
            $this->a1($sheet, 'A1'),
            new ValueRange(['values' => array_map(fn (array $r): array => $this->clean($r), $rows)]),
            ['valueInputOption' => 'RAW', 'insertDataOption' => 'INSERT_ROWS'],
        );

        return (string) $response->getUpdates()->getUpdatedRange();
    }

    private function api(): Sheets
    {
        if ($this->service !== null) {
            return $this->service;
        }
        $state = $this->describeCredentials();
        if (! $state['configured']) {
            throw new RuntimeException($state['hint']);
        }

        $client = new Client();
        $client->setApplicationName($this->config->applicationName);
        $client->setAuthConfig($this->config->credentialsFile);
        $client->setScopes([Sheets::SPREADSHEETS]);
        $client->setHttpClient(new HttpClient(['timeout' => $this->config->timeout, 'connect_timeout' => 10]));

        return $this->service = new Sheets($client);
    }

    private function a1(string $sheet, string $cells): string
    {
        return "'" . str_replace("'", "''", $sheet) . "'!" . $cells;
    }

    /**
     * @param list<scalar|null> $values
     *
     * @return list<string|int|float|bool>
     */
    private function clean(array $values): array
    {
        return array_map(static fn ($v) => $v ?? '', array_values($values));
    }
}
