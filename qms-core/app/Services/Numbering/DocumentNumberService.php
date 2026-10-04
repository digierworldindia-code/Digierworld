<?php

namespace App\Services\Numbering;

use App\Services\Settings\SettingsService;
use App\Core\Database;
use DateTimeImmutable;

/**
 * Gap-free report numbers such as SCA-2026-10-000001.
 *
 * allocate() must run inside the submit transaction: the counter row stays
 * locked until commit, and a rollback also rolls the increment back. The
 * UNIQUE (report_no, revision_no) key is the final guarantee.
 */
class DocumentNumberService
{
    public function __construct(
        private readonly Database $db,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $reportType report_types row
     */
    public function allocate(array $reportType, string $productionDate): string
    {
        $period = $this->periodKey($productionDate);
        $this->db->query(
            'INSERT INTO document_sequences (report_type_id, period_key, last_number) VALUES (?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)',
            [(int) $reportType['id'], $period],
        );
        $sequence = (int) $this->db->query('SELECT LAST_INSERT_ID() AS n')->getRow('n');

        return $this->format((string) $reportType['doc_prefix'], $productionDate, $sequence);
    }

    public function format(string $prefix, string $productionDate, int $sequence): string
    {
        $date    = new DateTimeImmutable($productionDate);
        $padding = max(3, min(9, $this->settings->int('documents.sequence_padding', 6)));
        $format  = $this->settings->string('documents.number_format', '{PREFIX}-{YYYY}-{MM}-{SEQ}');

        return strtr($format, [
            '{PREFIX}' => $prefix,
            '{YYYY}'   => $date->format('Y'),
            '{YY}'     => $date->format('y'),
            '{MM}'     => $date->format('m'),
            '{SEQ}'    => str_pad((string) $sequence, $padding, '0', STR_PAD_LEFT),
        ]);
    }

    public function periodKey(string $productionDate): string
    {
        $date = new DateTimeImmutable($productionDate);

        return match ($this->settings->string('documents.sequence_reset', 'MONTHLY')) {
            'YEARLY' => $date->format('Y'),
            'NEVER'  => 'ALL',
            default  => $date->format('Y-m'),
        };
    }

    /** Example of the next number for the first report type (settings preview, nothing allocated). */
    public function preview(): string
    {
        $type = $this->db->table('report_types')->orderBy('id')->get(1)->getRowArray();
        if ($type === null) {
            return '';
        }
        $date = service('productionCalendar')->current()['date'];
        $last = (int) ($this->db->table('document_sequences')
            ->where('report_type_id', $type['id'])
            ->where('period_key', $this->periodKey($date))
            ->get(1)->getRow('last_number') ?? 0);

        return $this->format((string) $type['doc_prefix'], $date, $last + 1);
    }
}
