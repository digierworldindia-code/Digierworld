<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Rows returned by a query. All values are strings (or null), exactly as MySQL
 * sends them: DECIMAL values are never turned into floats.
 */
final class Result
{
    /**
     * @param list<array<string, string|null>> $rows
     */
    public function __construct(private readonly array $rows)
    {
    }

    /**
     * @return list<array<string, string|null>>
     */
    public function getResultArray(): array
    {
        return $this->rows;
    }

    /**
     * @return list<object>
     */
    public function getResult(): array
    {
        return array_map(static fn (array $row): object => (object) $row, $this->rows);
    }

    /**
     * @return array<string, string|null>|null
     */
    public function getRowArray(int $n = 0): ?array
    {
        return $this->rows[$n] ?? null;
    }

    /**
     * getRow('column') returns that column of the first row (or null);
     * getRow(n) returns row n as an object.
     */
    public function getRow(int|string $n = 0): mixed
    {
        if (is_string($n)) {
            $first = $this->rows[0] ?? null;

            return $first !== null && array_key_exists($n, $first) ? $first[$n] : null;
        }

        return isset($this->rows[$n]) ? (object) $this->rows[$n] : null;
    }

    public function getNumRows(): int
    {
        return count($this->rows);
    }
}
