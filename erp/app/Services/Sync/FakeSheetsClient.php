<?php

namespace App\Services\Sync;

use RuntimeException;

/**
 * In-memory spreadsheet used by automated tests and the "dry run" check.
 * failNext() simulates Google outages/quota errors.
 */
final class FakeSheetsClient implements SheetsClient
{
    /** @var array<string, list<list<string>>> */
    public array $tabs = [];
    public int $calls = 0;
    public int $columnReads = 0;
    private int $failures = 0;

    public function failNext(int $times = 1): void
    {
        $this->failures = $times;
    }

    private function maybeFail(): void
    {
        $this->calls++;
        if ($this->failures > 0) {
            $this->failures--;

            throw new RuntimeException('Simulated Google API outage (503 backendError)');
        }
    }

    public function ensureSheet(string $title, array $headers): void
    {
        $this->maybeFail();
        $this->tabs[$title] ??= [];
        $this->tabs[$title][0] = array_map('strval', $headers);
    }

    public function columnA(string $title): array
    {
        $this->columnReads++;
        $this->maybeFail();

        return array_map(static fn ($r) => (string) ($r[0] ?? ''), $this->tabs[$title] ?? []);
    }

    public function appendRow(string $title, array $values): int
    {
        $this->maybeFail();
        $this->tabs[$title][] = array_map('strval', array_values($values));

        return count($this->tabs[$title]);
    }

    public function updateRow(string $title, int $row, array $values): void
    {
        $this->maybeFail();
        if (! isset($this->tabs[$title][$row - 1])) {
            throw new RuntimeException('Row ' . $row . ' does not exist');
        }
        $this->tabs[$title][$row - 1] = array_map('strval', array_values($values));
    }

    public function identity(): string
    {
        return 'fake-client@test';
    }

    /** Data rows (without header) for assertions. @return list<list<string>> */
    public function rows(string $title): array
    {
        return array_slice($this->tabs[$title] ?? [], 1);
    }
}
