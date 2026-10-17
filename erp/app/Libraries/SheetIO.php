<?php

namespace App\Libraries;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Native .xlsx/.csv reading and writing (PhpSpreadsheet).
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
        $book = new Spreadsheet();
        $book->getProperties()->setCreator('DGR Office')->setTitle($meta['Report'] ?? 'Export');
        $book->removeSheetByIndex(0);

        if ($meta !== []) {
            $about = $book->createSheet();
            $about->setTitle('About');
            $r = 1;
            foreach ($meta as $k => $v) {
                $about->setCellValue('A' . $r, $k);
                $about->setCellValueExplicit('B' . $r, (string) $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $about->getStyle('A' . $r)->getFont()->setBold(true);
                $r++;
            }
            $about->getColumnDimension('A')->setWidth(28);
            $about->getColumnDimension('B')->setWidth(90);
        }

        foreach ($sheets as $def) {
            $ws = $book->createSheet();
            $ws->setTitle(mb_substr(preg_replace('#[\\\\/?*\[\]:]#', ' ', (string) $def['title']), 0, 31));
            $cols = $def['columns'];
            foreach ($cols as $i => $c) {
                $cell = Coordinate::stringFromColumnIndex($i + 1) . '1';
                $ws->setCellValue($cell, $c['label']);
            }
            $last = Coordinate::stringFromColumnIndex(count($cols));
            $ws->getStyle("A1:{$last}1")->applyFromArray([
                'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1B1B1B']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
            $ws->getRowDimension(1)->setRowHeight(28);
            $r = 2;
            foreach ($def['rows'] as $row) {
                foreach ($cols as $i => $c) {
                    $cell = Coordinate::stringFromColumnIndex($i + 1) . $r;
                    $v    = $row[$c['key']] ?? null;
                    $type = $c['type'] ?? 'text';
                    if ($v === null || $v === '') {
                        continue;
                    }
                    match ($type) {
                        'money', 'percent', 'number' => $ws->setCellValueExplicit($cell, (float) $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC),
                        'int'   => $ws->setCellValueExplicit($cell, (int) $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC),
                        'date'  => $ws->setCellValue($cell, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(strtotime((string) $v) ?: 0)),
                        default => $ws->setCellValueExplicit($cell, (string) $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING),
                    };
                }
                $r++;
            }
            $lastRow = $r - 1;
            if (! empty($def['totals']) && $lastRow >= 2) {
                $ws->setCellValue('A' . $r, 'TOTAL');
                foreach ($cols as $i => $c) {
                    if (in_array($c['key'], $def['totals'], true)) {
                        $colL = Coordinate::stringFromColumnIndex($i + 1);
                        $ws->setCellValue($colL . $r, "=SUBTOTAL(9,{$colL}2:{$colL}{$lastRow})");
                    }
                }
                $ws->getStyle("A{$r}:{$last}{$r}")->applyFromArray([
                    'font'    => ['bold' => true],
                    'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM]],
                    'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F4EEE3']],
                ]);
            }
            foreach ($cols as $i => $c) {
                $colL = Coordinate::stringFromColumnIndex($i + 1);
                $fmt  = match ($c['type'] ?? 'text') {
                    'money'   => '#,##,##0.00;[Red]-#,##,##0.00',
                    'percent' => '0.00',
                    'number'  => '#,##0.000',
                    'int'     => '0',
                    'date'    => 'dd-mm-yyyy',
                    default   => null,
                };
                if ($fmt !== null) {
                    $ws->getStyle("{$colL}2:{$colL}" . max(2, $r))->getNumberFormat()->setFormatCode($fmt);
                }
                $ws->getColumnDimension($colL)->setWidth($c['width'] ?? match ($c['type'] ?? 'text') {
                    'money' => 15, 'date' => 12, 'int' => 8, 'percent' => 8, default => 22,
                });
            }
            $ws->freezePane('A2');
            if ($lastRow >= 2) {
                $ws->setAutoFilter("A1:{$last}{$lastRow}");
            }
            if (! empty($def['notes'])) {
                $n = $r + 2;
                foreach ($def['notes'] as $note) {
                    $ws->setCellValue('A' . $n++, $note);
                }
            }
        }
        $book->setActiveSheetIndex(0);

        $tmp = tempnam(WRITEPATH . 'tmp', 'xlsx');
        (new Xlsx($book))->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        $book->disconnectWorksheets();

        return $bytes;
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
