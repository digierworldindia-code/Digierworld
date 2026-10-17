<?php

namespace App\Services\Sync;

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\ValueRange;
use RuntimeException;

/**
 * Google Sheets API v4 via a service account. The JSON key stays on the
 * server (outside the web root); the spreadsheet must be shared with the
 * service account e-mail as Editor.
 */
final class GoogleSheetsClient implements SheetsClient
{
    private Sheets $sheets;
    private string $email;

    /** @var array<string, bool>|null */
    private ?array $tabs = null;

    public function __construct(private readonly string $spreadsheetId, string $credentialsPath)
    {
        if (! is_file($credentialsPath) || ! is_readable($credentialsPath)) {
            throw new RuntimeException('Service-account key file not found or not readable.');
        }
        $json = json_decode((string) file_get_contents($credentialsPath), true);
        if (! is_array($json) || ($json['type'] ?? '') !== 'service_account' || empty($json['client_email'])) {
            throw new RuntimeException('The key file is not a Google service-account JSON key.');
        }
        $this->email = (string) $json['client_email'];
        $client = new Client();
        $client->setApplicationName('DGR Office');
        $client->setAuthConfig($json);
        $client->setScopes([Sheets::SPREADSHEETS]);
        $client->setHttpClient(new \GuzzleHttp\Client(['timeout' => 20, 'connect_timeout' => 10]));
        $this->sheets = new Sheets($client);
    }

    private static function range(string $title, string $cells): string
    {
        return "'" . str_replace("'", "''", $title) . "'!" . $cells;
    }

    public function ensureSheet(string $title, array $headers): void
    {
        if ($this->tabs === null) {
            $this->tabs = [];
            foreach ($this->sheets->spreadsheets->get($this->spreadsheetId, ['fields' => 'sheets.properties.title'])->getSheets() as $s) {
                $this->tabs[$s->getProperties()->getTitle()] = true;
            }
        }
        if (! isset($this->tabs[$title])) {
            $this->sheets->spreadsheets->batchUpdate($this->spreadsheetId, new BatchUpdateSpreadsheetRequest([
                'requests' => [['addSheet' => ['properties' => ['title' => $title]]]],
            ]));
            $this->tabs[$title] = true;
        }
        $current = $this->sheets->spreadsheets_values->get($this->spreadsheetId, self::range($title, '1:1'))->getValues()[0] ?? [];
        if ($current !== $headers) {
            $this->sheets->spreadsheets_values->update($this->spreadsheetId, self::range($title, 'A1'), new ValueRange(['values' => [$headers]]), ['valueInputOption' => 'RAW']);
        }
    }

    public function columnA(string $title): array
    {
        $vals = $this->sheets->spreadsheets_values->get($this->spreadsheetId, self::range($title, 'A:A'))->getValues() ?? [];

        return array_map(static fn ($r) => (string) ($r[0] ?? ''), $vals);
    }

    public function appendRow(string $title, array $values): int
    {
        $res = $this->sheets->spreadsheets_values->append($this->spreadsheetId, self::range($title, 'A1'), new ValueRange(['values' => [array_values($values)]]),
            ['valueInputOption' => 'RAW', 'insertDataOption' => 'INSERT_ROWS']);
        $range = (string) $res->getUpdates()->getUpdatedRange();
        if (! preg_match('/!A(\d+)/', $range, $m)) {
            throw new RuntimeException('Google did not report the appended row (' . $range . ').');
        }

        return (int) $m[1];
    }

    public function updateRow(string $title, int $row, array $values): void
    {
        $this->sheets->spreadsheets_values->update($this->spreadsheetId, self::range($title, 'A' . $row), new ValueRange(['values' => [array_values($values)]]), ['valueInputOption' => 'RAW']);
    }

    public function identity(): string
    {
        return $this->email;
    }
}
