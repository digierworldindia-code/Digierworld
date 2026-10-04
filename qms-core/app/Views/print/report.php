<?php
/**
 * A4 printout of an inspection report (browser print or mPDF). Mirrors the
 * traditional paper formats; screen-only controls are hidden when printing.
 *
 * @var string                $mode      html | pdf
 * @var array<string, mixed>  $report
 * @var array<string, mixed>  $company
 * @var string                $ref
 * @var string                $orientation
 * @var string|null           $watermark
 * @var array<string, string> $times
 */
$r     = $report;
$isPdf = $mode === 'pdf';
$details = [['Report No.', $r['report_no'] ?? 'Not submitted'], ['Revision', (string) (int) $r['revision_no']], ['Status', \App\Enums\ReportStatus::from($r['status'])->label()]];
if ($r['layout'] === 'SECTIONED') {
    $details[] = ['Machine No.', $r['machine_code'] . ' · ' . $r['machine_name']];
    $details[] = ['Part Name', $r['part_name']];
    $details[] = ['Part No.', $r['part_number']];
} else {
    $details[] = ['Part No.', $r['part_number']];
    $details[] = ['Part Name', $r['part_name']];
    $details[] = [$r['layout'] === 'SHIFT_GRID' ? 'Machine Name' : 'Machine', $r['machine_code'] . ' · ' . $r['machine_name']];
}
$details[] = ['Date', plant_date($r['inspection_date'])];
if ($r['header_shift'] !== 'HIDDEN') {
    $details[] = ['Shift', (string) ($r['shift_code'] ?? '')];
}
if ($r['header_timing'] !== 'HIDDEN') {
    $details[] = ['Received Time', $times['received']];
    $details[] = ['Finish Time', $times['finish']];
}
if ($r['header_operator'] !== 'HIDDEN') {
    $details[] = ['Operator', trim(($r['operator_name'] ?? '') . ($r['operator_code'] !== null ? ' (' . $r['operator_code'] . ')' : ''))];
}
if ($r['header_setter'] !== 'HIDDEN') {
    $details[] = ['Setter', trim(($r['setter_name'] ?? '') . ($r['setter_code'] !== null ? ' (' . $r['setter_code'] . ')' : ''))];
}
if ($r['drawing_number'] !== null) {
    $details[] = ['Drawing', $r['drawing_number'] . ($r['drawing_revision'] !== null ? ' rev ' . $r['drawing_revision'] : '')];
}
$perRow = $r['layout'] === 'SHIFT_GRID' ? 4 : 3;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= esc($ref) ?> · <?= esc($company['name']) ?></title>
    <?php if ($isPdf): ?>
        <style><?= file_get_contents(FCPATH . 'assets/css/print.css') ?></style>
    <?php else: ?>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="<?= qms_asset('assets/css/print.css') ?>">
        <link rel="stylesheet" href="<?= qms_asset($orientation === 'L' ? 'assets/css/print-landscape.css' : 'assets/css/print-portrait.css') ?>">
    <?php endif ?>
</head>
<body class="sheet <?= $orientation === 'L' ? 'sheet-landscape' : 'sheet-portrait' ?>">
<?php if (! $isPdf): ?>
    <div class="toolbar no-print">
        <button type="button" data-print>Print</button>
        <a href="<?= site_url('inspections/' . $r['id'] . '/pdf?download=1') ?>">Download PDF</a>
        <a href="<?= site_url('inspections/' . $r['id']) ?>">Back to report</a>
        <span>A4 <?= $orientation === 'L' ? 'landscape' : 'portrait' ?>, scale 100 %, margins default.</span>
    </div>
    <?php if ($watermark !== null): ?><div class="watermark" aria-hidden="true"><?= esc($watermark) ?></div><?php endif ?>
<?php endif ?>
<div class="page-wrap">
    <table class="doc-head">
        <tr>
            <td class="logo" rowspan="2"><?php if ($company['logo'] !== null): ?><img src="<?= esc($company['logo'], 'attr') ?>" alt="<?= esc($company['name'], 'attr') ?>"><?php endif ?></td>
            <td class="company"><b class="co-name"><?= esc($company['name']) ?></b><?php if ($company['address'] !== ''): ?><br><span class="co-addr"><?= esc($company['address']) ?></span><?php endif ?></td>
            <td class="doc-ctl" rowspan="2">
                <table class="ctl">
                    <tr><th>Doc. No.</th><td><?= esc($r['format_doc_no']) ?></td></tr>
                    <tr><th>Rev. No.</th><td><?= esc($r['format_rev_no']) ?></td></tr>
                    <tr><th>Make date</th><td><?= esc(plant_date($r['format_made_date'])) ?></td></tr>
                    <tr><th>Rev. date</th><td><?= $r['format_rev_date'] !== null ? esc(plant_date($r['format_rev_date'])) : '–' ?></td></tr>
                </table>
            </td>
        </tr>
        <tr><td class="title"><?= esc(strtoupper($r['type_name'])) ?></td></tr>
    </table>

    <table class="details">
        <?php foreach (array_chunk($details, $perRow) as $chunk): ?>
            <tr>
                <?php foreach ($chunk as [$label, $value]): ?><th><?= esc($label) ?></th><td><?= esc((string) $value) ?></td><?php endforeach ?>
                <?php for ($i = count($chunk); $i < $perRow; $i++): ?><th></th><td></td><?php endfor ?>
            </tr>
        <?php endforeach ?>
    </table>

    <?= $r['layout'] === 'SHIFT_GRID' ? $this->include('print/_grid') : $this->include('print/_sheet') ?>

    <?php if (($r['remarks'] ?? '') !== ''): ?>
        <table class="remarks"><tr><th>Remarks</th><td><?= esc($r['remarks']) ?></td></tr></table>
    <?php endif ?>

    <?= $this->include('print/_signatures') ?>

    <p class="legend">✗ / bold underlined = outside specification (LSL – USL, limits inclusive). Result: PASS = all readings within limits.
        Electronically signed records; the signature history is kept in the QMS audit trail.</p>
    <?php if (! $isPdf): ?>
        <p class="printed">Printed by <?= esc($printedBy) ?> on <?= esc($printedAt) ?> · <?= esc($ref) ?> · Template <?= esc($r['template_code']) ?> v<?= (int) $r['template_version'] ?><?= $watermark !== null ? ' · ' . esc($watermark) : '' ?></p>
    <?php endif ?>
</div>
<?php if (! $isPdf): ?><script src="<?= qms_asset('assets/js/print.js') ?>"></script><?php endif ?>
</body>
</html>
