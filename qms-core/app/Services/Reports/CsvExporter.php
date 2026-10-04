<?php

namespace App\Services\Reports;

use App\Enums\ReportStatus;

/**
 * CSV export (UTF-8 with BOM for Excel).
 *
 * CSV / formula injection: a cell starting with = + - @ TAB or CR would be run as a
 * formula by Excel / Sheets, so such text is prefixed with an apostrophe. Plain
 * negative numbers are left untouched.
 */
final class CsvExporter
{
    public static function neutralise(string $value): string
    {
        if ($value === '' || ! in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return $value;
        }
        if (preg_match('/^-\d+(\.\d+)?$/', $value) === 1) {
            return $value;
        }

        return "'" . $value;
    }

    /**
     * @param array<string, array{label: string, type: string}> $columns
     * @param iterable<array<string, mixed>>                    $rows
     * @param array<string, mixed>|null                         $totals
     */
    public static function build(array $columns, iterable $rows, ?array $totals = null): string
    {
        $fh = fopen('php://temp', 'w+b');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_map(static fn (array $c): string => $c['label'], array_values($columns)), ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($fh, self::line($columns, $row), ',', '"', '');
        }
        if ($totals !== null) {
            fputcsv($fh, self::line($columns, $totals, true), ',', '"', '');
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        return $csv;
    }

    /**
     * @param array<string, array{label: string, type: string}> $columns
     * @param array<string, mixed>                              $row
     *
     * @return list<string>
     */
    private static function line(array $columns, array $row, bool $totals = false): array
    {
        $out = [];
        foreach ($columns as $key => $column) {
            $value = $row[$key] ?? null;
            if ($value === null || $value === '') {
                $out[] = '';

                continue;
            }
            $text = match ($totals ? 'text' : $column['type']) {
                'datetime' => service('clock')->toPlant((string) $value, 'Y-m-d H:i'),
                'status'   => ReportStatus::tryFrom((string) $value)?->label() ?? (string) $value,
                'result'   => (string) $value === 'FAIL' ? 'OUT OF SPEC' : (string) $value,
                default    => (string) $value,
            };
            $out[] = self::neutralise($text);
        }

        return $out;
    }
}
