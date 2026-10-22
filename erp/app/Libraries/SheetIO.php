<?php

namespace App\Libraries;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Native .xlsx/.csv reading (PhpSpreadsheet) and writing (XlsxWriter, CSV).
 *
 * Sheet definition for write():
 *  ['title' => 'Invoices', 'columns' => [['key' => 'number', 'label' => 'No.', 'type' => 'text|money|int|date|percent'], …],
 *   'rows' => [[...], …], 'totals' => ['money_key', …], 'notes' => ['line', …]]
 */
final class SheetIO
{
    /**
     * Read the first sheet into associative rows keyed by normalised header.
     *
     * @return array{headers: list<string>, rows: list<array<string, string>>}
     */
    public static function read(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === 'csv' || $ext === 'txt') {
            $reader = IOFactory::createReader('Csv');
            $reader->setInputEncoding(\PhpOffice\PhpSpreadsheet\Reader\Csv::GUESS_ENCODING);
            $reader->setDelimiter(self::csvDelimiter($path));
        } else {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
        }
        $sheet = $reader->load($path)->getActiveSheet();
        $data  = $sheet->toArray(null, true, false, false);
        if ($data === []) {
            return ['headers' => [], 'rows' => []];
        }
        $headers = array_map(static fn ($h) => self::key((string) $h), array_shift($data));
        $rows    = [];
        foreach ($data as $line) {
            if (count(array_filter($line, static fn ($v) => $v !== null && trim((string) $v) !== '')) === 0) {
                continue;
            }
            $row = [];
            foreach ($headers as $i => $h) {
                if ($h !== '') {
                    $v       = $line[$i] ?? '';
                    $row[$h] = is_float($v) && floor($v) === $v ? (string) (int) $v : trim((string) $v);
                }
            }
            $rows[] = $row;
        }

        return ['headers' => array_values(array_filter($headers)), 'rows' => $rows];
    }

    /**
     * The library's own guess can pick a space when a file starts with
     * free-text preamble lines (bank statements), so count real separators.
     */
    public static function csvDelimiter(string $path): string
    {
        $sample = (string) file_get_contents($path, false, null, 0, 65536);
        $sample = preg_replace('/"[^"]*"/', '""', $sample) ?? $sample; // ignore separators inside quotes
        $best   = ',';
        $max    = -1;
        foreach ([',', ';', "\t", '|'] as $d) {
            $n = substr_count($sample, $d);
            if ($n > $max) {
                [$best, $max] = [$d, $n];
            }
        }

        return $best;
    }

    public static function key(string $header): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($header))) ?? '', '_');
    }

    /**
     * Build a formatted multi-sheet .xlsx and return its bytes.
     *
     * @param list<array<string, mixed>> $sheets
     * @param array<string, string>      $meta   shown on a cover "About" sheet
     */
    public static function xlsx(array $sheets, array $meta = []): string
    {
        return XlsxWriter::build($sheets, $meta); // streamed row by row; see XlsxWriter
    }

    /**
     * CSV with UTF-8 BOM so Excel opens Indian text/₹ correctly.
     *
     * @param list<array{key: string, label: string}> $columns
     * @param list<array<string, mixed>>              $rows
     */
    public static function csv(array $columns, array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_column($columns, 'label'), ',', '"', '\\');
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $c) {
                $v = (string) ($row[$c['key']] ?? '');
                // neutralise spreadsheet formula injection
                if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) && ! is_numeric($v)) {
                    $v = "'" . $v;
                }
                $line[] = $v;
            }
            fputcsv($fh, $line, ',', '"', '\\');
        }
        rewind($fh);
        $out = (string) stream_get_contents($fh);
        fclose($fh);

        return $out;
    }
}
