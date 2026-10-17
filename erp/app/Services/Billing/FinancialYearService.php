<?php

namespace App\Services\Billing;

use CodeIgniter\Database\BaseConnection;
use Config\Billing;

/**
 * Indian financial years (default April–March, configurable start month)
 * and per-FY document series. When a new FY starts, series are created by
 * carrying the previous FY's prefix pattern forward (short code replaced)
 * with numbering reset to 1 — numbers are never reused within a FY.
 */
class FinancialYearService
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function startMonth(): int
    {
        $row = $this->db->table('company_settings')->select('fy_start_month')->where('id', 1)->get()->getRowArray();

        return (int) ($row['fy_start_month'] ?? 4) ?: 4;
    }

    /** @return array<string, mixed> FY row for the date (created if missing). */
    public function forDate(string $date): array
    {
        $fy  = fy_for_date($date, $this->startMonth());
        $row = $this->db->table('financial_years')->where('start_date', $fy['start_date'])->get()->getRowArray();
        if ($row === null) {
            $this->db->query(
                'INSERT IGNORE INTO financial_years (code, short_code, start_date, end_date, is_current) VALUES (?, ?, ?, ?, 0)',
                [$fy['code'], $fy['short_code'], $fy['start_date'], $fy['end_date']]
            );
            $row = $this->db->table('financial_years')->where('start_date', $fy['start_date'])->get()->getRowArray();
            $this->ensureSequences((int) $row['id']);
        }

        return $row;
    }

    /** @return array<string, mixed> */
    public function current(): array
    {
        $row = $this->forDate(date('Y-m-d'));
        if ((int) $row['is_current'] !== 1) {
            $this->db->table('financial_years')->update(['is_current' => 0]);
            $this->db->table('financial_years')->where('id', $row['id'])->update(['is_current' => 1]);
            $row['is_current'] = 1;
        }

        return $row;
    }

    /** Create any missing default series for a FY. */
    public function ensureSequences(int $fyId): void
    {
        $fy = $this->db->table('financial_years')->where('id', $fyId)->get()->getRowArray();
        if ($fy === null) {
            return;
        }
        $prevFy = $this->db->table('financial_years')->where('start_date <', $fy['start_date'])
            ->orderBy('start_date', 'DESC')->limit(1)->get()->getRowArray();

        foreach (config(Billing::class)->defaultSeries as $docType => $def) {
            $exists = $this->db->table('document_sequences')
                ->where(['doc_type' => $docType, 'financial_year_id' => $fyId])->countAllResults();
            if ($exists > 0) {
                continue;
            }
            $prefix  = str_replace('{fy}', $fy['short_code'], $def['prefix']);
            $padding = $def['padding'];
            $code    = $def['code'];
            $name    = $def['name'];
            if ($prevFy !== null) {
                $prev = $this->db->table('document_sequences')
                    ->where(['doc_type' => $docType, 'financial_year_id' => $prevFy['id'], 'is_default' => 1])
                    ->get()->getRowArray();
                if ($prev !== null) {
                    $prefix  = str_replace($prevFy['short_code'], $fy['short_code'], $prev['prefix']);
                    $padding = (int) $prev['padding'];
                    $code    = $prev['series_code'];
                    $name    = $prev['name'];
                }
            }
            $this->db->query(
                'INSERT IGNORE INTO document_sequences (series_code, doc_type, name, financial_year_id, prefix, suffix, next_number, padding, is_statutory, max_length, is_default, is_active)
                 VALUES (?, ?, ?, ?, ?, \'\', 1, ?, ?, 16, 1, 1)',
                [$code, $docType, $name, $fyId, $prefix, $padding, $def['statutory'] ? 1 : 0]
            );
        }
    }
}
