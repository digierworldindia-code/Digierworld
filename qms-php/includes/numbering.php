<?php
/**
 * Gap-free report numbers such as SCA-2026-10-000001.
 *
 * number_allocate() must run inside the submit transaction: the counter row
 * stays locked until commit, and a rollback also rolls the increment back.
 * The UNIQUE (report_no, revision_no) key is the final guarantee.
 */
defined('QMS') || exit;

function number_allocate(array $reportType, string $productionDate): string
{
    db_exec('INSERT INTO document_sequences (report_type_id, period_key, last_number) VALUES (?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)', [(int) $reportType['id'], number_period_key($productionDate)]);
    $sequence = (int) db_value('SELECT LAST_INSERT_ID()');

    return number_format_no((string) $reportType['doc_prefix'], $productionDate, $sequence);
}

function number_format_no(string $prefix, string $productionDate, int $sequence): string
{
    $date    = new DateTimeImmutable($productionDate);
    $padding = max(3, min(9, setting_int('documents.sequence_padding', 6)));
    $format  = setting_str('documents.number_format', '{PREFIX}-{YYYY}-{MM}-{SEQ}');

    return strtr($format, [
        '{PREFIX}' => $prefix,
        '{YYYY}'   => $date->format('Y'),
        '{YY}'     => $date->format('y'),
        '{MM}'     => $date->format('m'),
        '{SEQ}'    => str_pad((string) $sequence, $padding, '0', STR_PAD_LEFT),
    ]);
}

function number_period_key(string $productionDate): string
{
    $date = new DateTimeImmutable($productionDate);

    return match (setting_str('documents.sequence_reset', 'MONTHLY')) {
        'YEARLY' => $date->format('Y'),
        'NEVER'  => 'ALL',
        default  => $date->format('Y-m'),
    };
}

/** Example of the next number for the first report type (settings preview; nothing is allocated). */
function number_preview(): string
{
    $type = db_row('SELECT * FROM report_types ORDER BY id LIMIT 1');
    if ($type === null) {
        return '';
    }
    $date = production_current()['date'];
    $last = (int) db_value('SELECT last_number FROM document_sequences WHERE report_type_id = ? AND period_key = ?', [$type['id'], number_period_key($date)]);

    return number_format_no((string) $type['doc_prefix'], $date, $last + 1);
}
