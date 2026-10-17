<?php

namespace App\Libraries;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Parses bank statement exports (CSV/XLSX). Indian bank exports usually
 * have a preamble (bank name, account, period) before the transaction
 * table, so the header row is detected and the column mapping can be
 * confirmed by the user before anything is imported.
 */
final class BankStatementParser
{
    public const FIELDS = [
        'txn_date'    => 'Transaction date',
        'value_date'  => 'Value date',
        'description' => 'Description / narration',
        'reference'   => 'Reference / cheque / UTR',
        'debit'       => 'Withdrawal (debit)',
        'credit'      => 'Deposit (credit)',
        'amount'      => 'Amount (single column)',
        'drcr'        => 'Dr/Cr indicator',
        'balance'     => 'Balance',
    ];

    private const HINTS = [
        'txn_date'    => ['txn date', 'transaction date', 'tran date', 'date', 'posting date', 'trans date'],
        'value_date'  => ['value date', 'value dt'],
        'description' => ['narration', 'description', 'particulars', 'remarks', 'transaction remarks', 'details', 'transaction details'],
        'reference'   => ['chq / ref no', 'chq/ref no', 'ref no', 'reference', 'cheque no', 'chq no', 'utr', 'ref no./cheque no.', 'instrument no', 'transaction id'],
        'debit'       => ['withdrawal amt', 'withdrawal', 'withdrawals', 'debit', 'debit amount', 'dr amount', 'withdrawal amount (inr )', 'withdrawal amount'],
        'credit'      => ['deposit amt', 'deposit', 'deposits', 'credit', 'credit amount', 'cr amount', 'deposit amount (inr )', 'deposit amount'],
        'amount'      => ['amount', 'transaction amount', 'amount (inr)'],
        'drcr'        => ['dr/cr', 'cr/dr', 'type', 'dr / cr', 'debit/credit'],
        'balance'     => ['closing balance', 'balance', 'running balance', 'balance (inr )', 'available balance'],
    ];

    /** @return list<list<string>> all non-empty rows as strings */
    public static function readRaw(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === 'csv' || $ext === 'txt') {
            $reader = IOFactory::createReader('Csv');
            $reader->setInputEncoding(Csv::GUESS_ENCODING);
            $reader->setDelimiter(SheetIO::csvDelimiter($path));
        } else {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
        }
        $rows = [];
        foreach ($reader->load($path)->getActiveSheet()->toArray(null, true, false, false) as $line) {
            $cells = array_map(static fn ($v) => is_float($v) || is_int($v) ? (string) $v : trim((string) $v), $line);
            if (implode('', $cells) !== '') {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    private static function norm(string $h): string
    {
        return trim(preg_replace('/\s+/', ' ', strtolower(str_replace(['.', '_'], ' ', $h))) ?? '');
    }

    /** Index of the row that looks like the transaction table header (or -1). */
    public static function detectHeader(array $rows): int
    {
        foreach (array_slice($rows, 0, 40, true) as $i => $row) {
            $map = self::guessMapping($row);
            $hasDate   = isset($map['txn_date']);
            $hasAmount = isset($map['debit']) || isset($map['credit']) || isset($map['amount']);
            if ($hasDate && $hasAmount) {
                return $i;
            }
        }

        return -1;
    }

    /** @return array<string, int> field => column index */
    public static function guessMapping(array $header): array
    {
        $map  = [];
        $used = [];
        $norm = array_map(static fn ($h) => self::norm((string) $h), $header);
        // exact matches first, then "contains", so "value date" never becomes the txn date
        foreach ([true, false] as $exact) {
            foreach (self::HINTS as $field => $hints) {
                if (isset($map[$field])) {
                    continue;
                }
                foreach ($norm as $i => $h) {
                    if ($h === '' || isset($used[$i])) {
                        continue;
                    }
                    foreach ($hints as $hint) {
                        if ($exact ? $h === $hint : str_contains($h, $hint)) {
                            if ($field === 'txn_date' && str_contains($h, 'value')) {
                                continue 2;
                            }
                            $map[$field] = $i;
                            $used[$i]    = true;

                            continue 3;
                        }
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Turn rows below the header into statement lines.
     *
     * @param array<string, int|null> $map
     *
     * @return array{lines: list<array<string, mixed>>, errors: list<string>}
     */
    public static function parse(array $rows, int $headerIndex, array $map): array
    {
        $lines  = [];
        $errors = [];
        foreach (array_slice($rows, $headerIndex + 1, null, true) as $i => $row) {
            $get  = static fn (string $f) => isset($map[$f]) && $map[$f] !== null && $map[$f] >= 0 ? trim((string) ($row[$map[$f]] ?? '')) : '';
            $rawD = $get('txn_date');
            $date = self::date($rawD);
            if ($date === null) {
                // footer rows ("Opening balance", "*** End of statement", totals) are skipped silently
                if ($rawD !== '' && preg_match('/\d/', $rawD) && count($lines) > 0 && ! preg_match('/total|balance|statement/i', implode(' ', $row))) {
                    $errors[] = 'Row ' . ($i + 1) . ': unreadable date "' . $rawD . '"';
                }

                continue;
            }
            $debit  = self::amount($get('debit'));
            $credit = self::amount($get('credit'));
            if (isset($map['amount']) && $map['amount'] !== null && $map['amount'] >= 0 && $debit === null && $credit === null) {
                $amt = self::amount($get('amount'));
                if ($amt !== null) {
                    $flag = strtolower($get('drcr') . ' ' . $get('amount'));
                    $isDr = str_contains($flag, 'dr') || str_contains($flag, 'debit') || Dec::isNegative($amt);
                    [$debit, $credit] = $isDr ? [Dec::abs($amt), null] : [null, Dec::abs($amt)];
                }
            }
            $debit  = $debit !== null ? Dec::abs($debit) : '0.00';
            $credit = $credit !== null ? Dec::abs($credit) : '0.00';
            if (Dec::isZero($debit) && Dec::isZero($credit)) {
                continue;
            }
            if (! Dec::isZero($debit) && ! Dec::isZero($credit)) {
                $errors[] = 'Row ' . ($i + 1) . ': has both a debit and a credit';

                continue;
            }
            $lines[] = [
                'row'         => $i + 1,
                'txn_date'    => $date,
                'value_date'  => self::date($get('value_date')),
                'description' => mb_substr($get('description'), 0, 500),
                'reference'   => mb_substr($get('reference'), 0, 120) ?: null,
                'debit'       => $debit,
                'credit'      => $credit,
                'balance'     => self::amount($get('balance')),
            ];
        }

        return ['lines' => $lines, 'errors' => $errors];
    }

    /** Dates as banks print them: 05/04/2026, 05-04-26, 05-Apr-2026, 2026-04-05, Excel serials. */
    public static function date(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d{5}(\.\d+)?$/', $v)) {
            return ExcelDate::excelToDateTimeObject((float) $v)->format('Y-m-d');
        }
        $v = preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?\s*(am|pm)?$/i', '', $v) ?? $v;
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $v, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
        }
        if (preg_match('/^(\d{1,2})[\/\-. ](\d{1,2})[\/\-. ](\d{2,4})$/', $v, $m)) {
            $y = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];

            return checkdate((int) $m[2], (int) $m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : null;
        }
        if (preg_match('/^(\d{1,2})[\/\-. ]([A-Za-z]{3,9})[\/\-., ]+(\d{2,4})$/', $v, $m)) {
            $mon = date_parse($m[2] . ' 1 2000')['month'] ?? false;
            $y   = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];

            return $mon && checkdate((int) $mon, (int) $m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $mon, $m[1]) : null;
        }

        return null;
    }

    /** "1,23,456.70", "₹ 500.00 Cr", "(250.00)", "-250" → decimal string; null if empty/invalid. */
    public static function amount(string $v): ?string
    {
        $v = trim(str_ireplace(['₹', 'inr', 'rs.', 'rs', ','], '', $v));
        if ($v === '' || $v === '-') {
            return null;
        }
        $neg = false;
        if (preg_match('/^\((.*)\)$/', $v, $m)) {
            $v   = $m[1];
            $neg = true;
        }
        if (preg_match('/\s*(dr|cr)\.?$/i', $v, $m)) {
            $neg = $neg || strtolower($m[1]) === 'dr';
            $v   = trim(substr($v, 0, -strlen($m[0])));
        }
        if (! preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            return null;
        }

        return Dec::round($neg && $v[0] !== '-' ? '-' . $v : $v);
    }
}
