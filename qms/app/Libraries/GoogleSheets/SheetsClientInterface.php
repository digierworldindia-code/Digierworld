<?php

namespace App\Libraries\GoogleSheets;

/**
 * The only Google Sheets operations the QMS needs. Implemented by
 * GoogleSheetsClient (real API) and FakeSheetsClient (tests).
 */
interface SheetsClientInterface
{
    /**
     * @return array{configured: bool, email: string|null, hint: string}
     */
    public function describeCredentials(): array;

    public function spreadsheetTitle(string $spreadsheetId): string;

    /**
     * @return array<string, array{sheetId: int, rows: int, cols: int}> keyed by tab title
     */
    public function sheets(string $spreadsheetId): array;

    /**
     * Creates a tab with a frozen header row, protected so only the service account edits it.
     *
     * @param list<string> $headers
     */
    public function addSheet(string $spreadsheetId, string $title, array $headers): void;

    /**
     * Values of the first column (the sync key column) of a tab, row 1 first.
     *
     * @return list<string>
     */
    public function readKeyColumn(string $spreadsheetId, string $sheet): array;

    /**
     * Overwrites one row. $row is 1-based. Returns the A1 range written.
     *
     * @param list<scalar|null> $values
     */
    public function updateRow(string $spreadsheetId, string $sheet, int $row, array $values): string;

    /**
     * Appends rows after the last row with data. Returns the A1 range written.
     *
     * @param list<list<scalar|null>> $rows
     */
    public function appendRows(string $spreadsheetId, string $sheet, array $rows): string;
}
