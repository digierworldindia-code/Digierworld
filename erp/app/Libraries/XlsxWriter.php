<?php

namespace App\Libraries;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Writes exports as .xlsx row by row to a temporary file, so memory stays
 * flat however many rows there are (PhpSpreadsheet keeps every cell in
 * memory: about 255 MB and 9 s for a 12,000-lead CRM export, which runs
 * into shared-hosting memory limits and gateway timeouts).
 *
 * Same layout as before: an "About" sheet, a dark bold header row that
 * stays frozen with a filter, Indian number formats, column widths, an
 * optional SUBTOTAL totals row and notes. Text is always written as text,
 * never as a formula.
 */
final class XlsxWriter
{
    /** cellXfs indexes in styles.xml */
    private const STYLE = [
        'text' => 0, 'header' => 1, 'money' => 2, 'percent' => 3, 'number' => 4, 'int' => 5, 'date' => 6, 'bold' => 7,
        'total_text' => 8, 'total_money' => 9, 'total_percent' => 10, 'total_number' => 11, 'total_int' => 12, 'total_date' => 13,
    ];

    /**
     * @param list<array{title: string, columns: list<array<string, mixed>>, rows: iterable<array<string, mixed>>, totals?: list<string>, notes?: list<string>}> $sheets
     * @param array<string, string> $meta shown on the first sheet ("About")
     */
    public static function build(array $sheets, array $meta = []): string
    {
        $dir   = rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR;
        $zipAt = tempnam($dir, 'xlsx');
        $parts = [];

        try {
            $defs = [];
            if ($meta !== []) {
                $rows = [];
                foreach ($meta as $k => $v) {
                    $rows[] = ['k' => (string) $k, 'v' => (string) $v];
                }
                $defs[] = ['title' => 'About', 'about' => true, 'columns' => [['key' => 'k', 'label' => '', 'width' => 28], ['key' => 'v', 'label' => '', 'width' => 90]], 'rows' => $rows];
            }
            foreach ($sheets as $s) {
                $defs[] = $s;
            }

            $names = [];
            foreach ($defs as $i => $def) {
                $name = mb_substr(trim((string) preg_replace('#[\\\\/?*\[\]:]#', ' ', (string) $def['title'])), 0, 31) ?: 'Sheet' . ($i + 1);
                $base = $name;
                for ($n = 2; in_array(mb_strtolower($name), array_map('mb_strtolower', $names), true); $n++) {
                    $name = mb_substr($base, 0, 28) . ' ' . $n;
                }
                $names[] = $name;
                $parts[] = self::writeSheet($dir, $def, $i === 0);
            }

            $zip = new ZipArchive();
            if ($zip->open($zipAt, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot create the spreadsheet file.');
            }
            $zip->addFromString('[Content_Types].xml', self::contentTypes(count($defs)));
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
                . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
                . '</Relationships>');
            $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
                . '<dc:creator>DGR Office</dc:creator><dc:title>' . self::x((string) ($meta['Report'] ?? 'Export')) . '</dc:title>'
                . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>');
            $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>DGR Office</Application></Properties>');
            $zip->addFromString('xl/workbook.xml', self::workbook($names, $defs));
            $rels = '';
            foreach ($names as $i => $_) {
                $rels .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
            }
            $rels .= '<Relationship Id="rId' . (count($names) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
            $zip->addFromString('xl/styles.xml', self::styles());
            foreach ($parts as $i => $p) {
                $zip->addFile($p['file'], 'xl/worksheets/sheet' . ($i + 1) . '.xml');
            }
            if (! $zip->close()) {
                throw new RuntimeException('Cannot write the spreadsheet file.');
            }

            return (string) file_get_contents($zipAt);
        } finally {
            foreach ($parts as $p) {
                @unlink($p['file']);
            }
            @unlink($zipAt);
        }
    }

    /** @return array{file: string, rows: int, filter: ?string} */
    private static function writeSheet(string $dir, array $def, bool $selected): array
    {
        $file  = tempnam($dir, 'xls');
        $fh    = fopen($file, 'wb');
        $cols  = array_values($def['columns']);
        $about = ! empty($def['about']);
        $last  = self::col(count($cols));

        fwrite($fh, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetViews><sheetView workbookViewId="0"' . ($selected ? ' tabSelected="1"' : '') . '>'
            . ($about ? '' : '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>')
            . '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/><cols>');
        foreach ($cols as $i => $c) {
            $w = (float) ($c['width'] ?? match ($c['type'] ?? 'text') { 'money' => 15, 'date' => 12, 'int' => 8, 'percent' => 8, default => 22 });
            fwrite($fh, '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>');
        }
        fwrite($fh, '</cols><sheetData>');

        $r = 1;
        if (! $about) {
            fwrite($fh, '<row r="1" ht="28" customHeight="1">');
            foreach ($cols as $i => $c) {
                fwrite($fh, self::str(self::col($i + 1) . '1', (string) $c['label'], self::STYLE['header']));
            }
            fwrite($fh, '</row>');
            $r = 2;
        }
        foreach ($def['rows'] as $row) {
            $cells = '';
            foreach ($cols as $i => $c) {
                $v = $row[$c['key']] ?? null;
                if ($v === null || $v === '') {
                    continue;
                }
                $ref = self::col($i + 1) . $r;
                if ($about) {
                    $cells .= self::str($ref, (string) $v, $i === 0 ? self::STYLE['bold'] : self::STYLE['text']);

                    continue;
                }
                $type   = $c['type'] ?? 'text';
                $cells .= match ($type) {
                    'money', 'percent', 'number' => self::num($ref, (float) $v, self::STYLE[$type]),
                    'int'   => self::num($ref, (int) $v, self::STYLE['int']),
                    'date'  => ($serial = self::serial((string) $v)) === null ? self::str($ref, (string) $v, self::STYLE['text']) : self::num($ref, $serial, self::STYLE['date']),
                    default => self::str($ref, (string) $v, self::STYLE['text']),
                };
            }
            fwrite($fh, '<row r="' . $r . '">' . $cells . '</row>');
            $r++;
        }
        $lastRow = $r - 1;
        if (! $about && ! empty($def['totals']) && $lastRow >= 2) {
            $cells = self::str('A' . $r, 'TOTAL', self::STYLE['total_text']);
            foreach ($cols as $i => $c) {
                if ($i === 0) {
                    continue;
                }
                $type  = $c['type'] ?? 'text';
                $style = self::STYLE['total_' . (isset(self::STYLE['total_' . $type]) ? $type : 'text')];
                $L     = self::col($i + 1);
                $cells .= in_array($c['key'], $def['totals'], true)
                    ? '<c r="' . $L . $r . '" s="' . $style . '"><f>SUBTOTAL(9,' . $L . '2:' . $L . $lastRow . ')</f></c>'
                    : '<c r="' . $L . $r . '" s="' . $style . '"/>';
            }
            fwrite($fh, '<row r="' . $r . '">' . $cells . '</row>');
            $r++;
        }
        if (! empty($def['notes'])) {
            $n = $r + 1;
            foreach ($def['notes'] as $note) {
                fwrite($fh, '<row r="' . $n . '">' . self::str('A' . $n, (string) $note, self::STYLE['text']) . '</row>');
                $n++;
            }
        }
        fwrite($fh, '</sheetData>');
        $filter = ! $about && $lastRow >= 2 ? 'A1:' . $last . $lastRow : null;
        if ($filter !== null) {
            fwrite($fh, '<autoFilter ref="' . $filter . '"/>');
        }
        fwrite($fh, '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/></worksheet>');
        fclose($fh);

        return ['file' => $file, 'rows' => $lastRow, 'filter' => $filter];
    }

    private static function workbook(array $names, array $defs): string
    {
        $sheets  = '';
        $defined = '';
        foreach ($names as $i => $name) {
            $sheets .= '<sheet name="' . self::x($name) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }
        foreach ($defs as $i => $def) {
            $rows = is_array($def['rows']) ? count($def['rows']) : 0;
            if (empty($def['about']) && $rows > 0) {
                $defined .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\'' . str_replace("'", "''", self::x($names[$i])) . '\'!$A$1:$' . self::col(count($def['columns'])) . '$' . ($rows + 1) . '</definedName>';
            }
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView activeTab="0"/></bookViews><sheets>' . $sheets . '</sheets>'
            . ($defined !== '' ? '<definedNames>' . $defined . '</definedNames>' : '')
            . '<calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>';
    }

    private static function styles(): string
    {
        $num  = ['money' => 164, 'percent' => 2, 'number' => 165, 'int' => 1, 'date' => 166];
        $xf   = static fn (int $numFmt, int $font, int $fill, int $border, string $extra = '') => '<xf numFmtId="' . $numFmt . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '" xfId="0"'
            . ($numFmt ? ' applyNumberFormat="1"' : '') . ($font ? ' applyFont="1"' : '') . ($fill ? ' applyFill="1"' : '') . ($border ? ' applyBorder="1"' : '') . ($extra !== '' ? ' applyAlignment="1">' . $extra . '</xf>' : '/>');
        $cellXfs = [
            $xf(0, 0, 0, 0),                                                                // text
            $xf(0, 1, 2, 0, '<alignment vertical="center" wrapText="1"/>'),                // header
            $xf($num['money'], 0, 0, 0), $xf($num['percent'], 0, 0, 0), $xf($num['number'], 0, 0, 0), $xf($num['int'], 0, 0, 0), $xf($num['date'], 0, 0, 0),
            $xf(0, 2, 0, 0),                                                                // bold
            $xf(0, 2, 3, 1),                                                                // totals
            $xf($num['money'], 2, 3, 1), $xf($num['percent'], 2, 3, 1), $xf($num['number'], 2, 3, 1), $xf($num['int'], 2, 3, 1), $xf($num['date'], 2, 3, 1),
        ];

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="3"><numFmt numFmtId="164" formatCode="#,##,##0.00;[Red]-#,##,##0.00"/><numFmt numFmtId="165" formatCode="#,##0.000"/><numFmt numFmtId="166" formatCode="dd-mm-yyyy"/></numFmts>'
            . '<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1B1B1B"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF4EEE3"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left/><right/><top style="medium"><color auto="1"/></top><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($cellXfs) . '">' . implode('', $cellXfs) . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private static function contentTypes(int $sheets): string
    {
        $ws = '';
        for ($i = 1; $i <= $sheets; $i++) {
            $ws .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . $ws . '</Types>';
    }

    /** Excel serial date (days since 30-12-1899, in the app's time zone); null when the text is not a date. */
    private static function serial(string $value): ?float
    {
        try {
            $tz = new DateTimeZone(config('App')->appTimezone ?: 'Asia/Kolkata');
            $d  = new DateTimeImmutable($value, $tz);
        } catch (Throwable) {
            return null;
        }
        $days = (int) (new DateTimeImmutable('1899-12-30', $tz))->diff($d->setTime(0, 0))->format('%a');
        $secs = (int) $d->format('H') * 3600 + (int) $d->format('i') * 60 + (int) $d->format('s');

        return $days + $secs / 86400;
    }

    private static function str(string $ref, string $text, int $style): string
    {
        return '<c r="' . $ref . '" t="inlineStr"' . ($style ? ' s="' . $style . '"' : '') . '><is><t xml:space="preserve">' . self::x(mb_substr($text, 0, 32767)) . '</t></is></c>';
    }

    private static function num(string $ref, int|float $n, int $style): string
    {
        return '<c r="' . $ref . '" s="' . $style . '"><v>' . (is_float($n) && ! is_finite($n) ? 0 : var_export($n, true)) . '</v></c>';
    }

    /** XML-escaped text without the control characters XML does not allow. */
    private static function x(string $s): string
    {
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', mb_scrub($s, 'UTF-8'));

        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function col(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $s = chr(65 + $m) . $s;
            $n = intdiv($n - 1, 26);
        }

        return $s;
    }
}
