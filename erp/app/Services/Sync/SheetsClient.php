<?php

namespace App\Services\Sync;

/** Minimal spreadsheet operations the sync needs (one worksheet = one tab). */
interface SheetsClient
{
    /** Create the tab if missing and make sure row 1 holds these headers. */
    public function ensureSheet(string $title, array $headers): void;

    /** Values of column A (row 1 = header). @return list<string> */
    public function columnA(string $title): array;

    /** Append a row; returns the 1-based row number written. */
    public function appendRow(string $title, array $values): int;

    /** Overwrite one row (1-based). */
    public function updateRow(string $title, int $row, array $values): void;

    /** Human-readable identity (e.g. service account e-mail) for the status screen. */
    public function identity(): string;
}
