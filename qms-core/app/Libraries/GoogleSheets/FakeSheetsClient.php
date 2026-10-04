<?php

namespace App\Libraries\GoogleSheets;

use RuntimeException;

/**
 * In-memory Sheets for tests and local development without Google credentials.
 * Can simulate failures: queue exceptions with failNext().
 */
class FakeSheetsClient implements SheetsClientInterface
{
    /** @var array<string, array<string, list<list<scalar|null>>>> spreadsheet => tab => rows (row 1 = header) */
    public array $data = [];

    /** @var list<\Throwable> */
    private array $failures = [];

    public int $calls = 0;

    public function failNext(\Throwable $e): void
    {
        $this->failures[] = $e;
    }

    public function describeCredentials(): array
    {
        return ['configured' => true, 'email' => 'qms-test@example.iam.gserviceaccount.com', 'hint' => 'Fake client'];
    }

    public function spreadsheetTitle(string $spreadsheetId): string
    {
        $this->tick();

        return 'Fake ' . $spreadsheetId;
    }

    public function sheets(string $spreadsheetId): array
    {
        $this->tick();
        $out = [];
        $i   = 0;
        foreach ($this->data[$spreadsheetId] ?? [] as $title => $rows) {
            $out[$title] = ['sheetId' => ++$i, 'rows' => max(1000, count($rows)), 'cols' => 26];
        }

        return $out;
    }

    public function addSheet(string $spreadsheetId, string $title, array $headers): void
    {
        $this->tick();
        $this->data[$spreadsheetId][$title] = [$headers];
    }

    public function readKeyColumn(string $spreadsheetId, string $sheet): array
    {
        $this->tick();

        return array_map(static fn (array $row): string => (string) ($row[0] ?? ''), $this->tab($spreadsheetId, $sheet));
    }

    public function updateRow(string $spreadsheetId, string $sheet, int $row, array $values): string
    {
        $this->tick();
        $this->tab($spreadsheetId, $sheet);
        $this->data[$spreadsheetId][$sheet][$row - 1] = array_values($values);

        return "'{$sheet}'!A{$row}";
    }

    public function appendRows(string $spreadsheetId, string $sheet, array $rows): string
    {
        $this->tick();
        $this->tab($spreadsheetId, $sheet);
        $first = count($this->data[$spreadsheetId][$sheet]) + 1;
        foreach ($rows as $row) {
            $this->data[$spreadsheetId][$sheet][] = array_values($row);
        }
        $last = $first + count($rows) - 1;

        return "'{$sheet}'!A{$first}:Z{$last}";
    }

    /**
     * @return list<list<scalar|null>>
     */
    private function tab(string $spreadsheetId, string $sheet): array
    {
        if (! isset($this->data[$spreadsheetId][$sheet])) {
            throw new RuntimeException("Unable to parse range: '{$sheet}'!A1 (400)", 400);
        }

        return $this->data[$spreadsheetId][$sheet];
    }

    private function tick(): void
    {
        $this->calls++;
        if ($this->failures !== []) {
            throw array_shift($this->failures);
        }
    }
}
