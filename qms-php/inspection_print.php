<?php
/**
 * A4 printout of an inspection report. "Print / Save as PDF" opens the browser's
 * print dialog; choose "Save as PDF" there for a PDF file (no PDF library needed).
 * Every print view is recorded in the audit trail.
 */
require __DIR__ . '/includes/init.php';
$user = require_permission('inspection.print');
require_once QMS_ROOT . '/includes/print.php';

$model = print_model(get_int('id'), $user);
audit_log('PRINT', 'inspection', get_int('id'), null, ['format' => 'HTML'], $model['ref']);

$r       = $model['report'];
$company = $model['company'];
$times   = $model['times'];
$details = [['Report No.', $r['report_no'] ?? 'Not submitted'], ['Revision', (string) (int) $r['revision_no']], ['Status', status_label($r['status'])]];
if ($r['layout'] === 'SECTIONED') {
    array_push($details, ['Machine No.', $r['machine_code'] . ' · ' . $r['machine_name']], ['Part Name', $r['part_name']], ['Part No.', $r['part_number']]);
} else {
    array_push($details, ['Part No.', $r['part_number']], ['Part Name', $r['part_name']], [$r['layout'] === 'SHIFT_GRID' ? 'Machine Name' : 'Machine', $r['machine_code'] . ' · ' . $r['machine_name']]);
}
$details[] = ['Date', plant_date($r['inspection_date'])];
if ($r['header_shift'] !== 'HIDDEN') {
    $details[] = ['Shift', (string) ($r['shift_code'] ?? '')];
}
if ($r['header_timing'] !== 'HIDDEN') {
    array_push($details, ['Received Time', $times['received']], ['Finish Time', $times['finish']]);
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
$perRow    = $r['layout'] === 'SHIFT_GRID' ? 4 : 3;
$landscape = $model['orientation'] === 'L';
$report    = $r;
$steps     = $model['steps'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($model['ref']) ?> · <?= e($company['name']) ?></title>
    <link rel="stylesheet" href="<?= asset('css/print.css') ?>">
    <link rel="stylesheet" href="<?= asset($landscape ? 'css/print-landscape.css' : 'css/print-portrait.css') ?>">
</head>
<body class="sheet <?= $landscape ? 'sheet-landscape' : 'sheet-portrait' ?>">
<div class="toolbar no-print">
    <button type="button" data-print>Print / Save as PDF</button>
    <a href="<?= url('inspection.php?id=' . (int) $r['id']) ?>">Back to report</a>
    <span>A4 <?= $landscape ? 'landscape' : 'portrait' ?>, scale 100 %. For a PDF file choose "Save as PDF" as the printer.</span>
</div>
<?php if ($model['watermark'] !== null): ?><div class="watermark" aria-hidden="true"><?= e($model['watermark']) ?></div><?php endif ?>
<div class="page-wrap">
    <table class="doc-head">
        <tr>
            <td class="logo" rowspan="2"><?php if ($company['logo'] !== null): ?><img src="<?= e($company['logo']) ?>" alt="<?= e($company['name']) ?>"><?php endif ?></td>
            <td class="company"><b class="co-name"><?= e($company['name']) ?></b><?php if ($company['address'] !== ''): ?><br><span class="co-addr"><?= e($company['address']) ?></span><?php endif ?></td>
            <td class="doc-ctl" rowspan="2">
                <table class="ctl">
                    <tr><th>Doc. No.</th><td><?= e($r['format_doc_no']) ?></td></tr>
                    <tr><th>Rev. No.</th><td><?= e($r['format_rev_no']) ?></td></tr>
                    <tr><th>Make date</th><td><?= e(plant_date($r['format_made_date'])) ?></td></tr>
                    <tr><th>Rev. date</th><td><?= $r['format_rev_date'] !== null ? e(plant_date($r['format_rev_date'])) : '–' ?></td></tr>
                </table>
            </td>
        </tr>
        <tr><td class="title"><?= e(strtoupper($r['type_name'])) ?></td></tr>
    </table>

    <table class="details">
        <?php foreach (array_chunk($details, $perRow) as $chunk): ?>
            <tr>
                <?php foreach ($chunk as [$label, $value]): ?><th><?= e($label) ?></th><td><?= e((string) $value) ?></td><?php endforeach ?>
                <?php for ($i = count($chunk); $i < $perRow; $i++): ?><th></th><td></td><?php endfor ?>
            </tr>
        <?php endforeach ?>
    </table>

    <?php
    if ($r['layout'] === 'SHIFT_GRID') {
        $gridShifts = $model['gridShifts'];
        $gridRows   = $model['gridRows'];
        $totals     = $model['totals'];
        require QMS_ROOT . '/includes/views/print/grid.php';
    } else {
        $sections      = $model['sections'];
        $maxObs        = $model['maxObs'];
        $methodColumns = $model['methodColumns'];
        require QMS_ROOT . '/includes/views/print/sheet.php';
    }
    ?>

    <?php if (($r['remarks'] ?? '') !== ''): ?>
        <table class="remarks"><tr><th>Remarks</th><td><?= e($r['remarks']) ?></td></tr></table>
    <?php endif ?>

    <?php require QMS_ROOT . '/includes/views/print/signatures.php'; ?>

    <p class="legend">✗ / bold underlined = outside specification (LSL – USL, limits inclusive). Result: PASS = all readings within limits.
        Electronically signed records; the signature history is kept in the QMS audit trail.</p>
    <p class="printed">Printed by <?= e($model['printedBy']) ?> on <?= e($model['printedAt']) ?> · <?= e($model['ref']) ?> · Template <?= e($r['template_code']) ?> v<?= (int) $r['template_version'] ?><?= $model['watermark'] !== null ? ' · ' . e($model['watermark']) : '' ?></p>
</div>
<script src="<?= asset('js/print.js') ?>"></script>
</body>
</html>
