<?php
/**
 * Setup Change Approval (sections) and Product Parameter Inspection (method
 * matrix: one column per inspection method, ✓ marks the method used).
 * Variables: $report, $sections, $maxObs, $methodColumns.
 */
defined('QMS') || exit;

$matrix  = $report['layout'] === 'METHOD_MATRIX';
$columns = 5 + ($matrix ? count($methodColumns) : 3) + $maxObs + 3;
?>
<table class="params">
    <thead>
    <tr>
        <th class="c-sr">Sr.</th>
        <th class="c-name">Parameter</th>
        <th class="c-spec">Specification</th>
        <th class="c-lim">LSL</th>
        <th class="c-lim">USL</th>
        <?php if ($matrix): ?>
            <?php foreach ($methodColumns as $m): ?><th class="c-method"><?= e($m['short_label']) ?></th><?php endforeach ?>
        <?php else: ?>
            <th class="c-unit">Unit</th>
            <th class="c-method">Method</th>
            <th class="c-inst">Gauge / instrument</th>
        <?php endif ?>
        <th class="c-gauge">Gauge ID</th>
        <?php for ($n = 1; $n <= $maxObs; $n++): ?><th class="c-obs">Obs. <?= $n ?></th><?php endfor ?>
        <th class="c-res">Result</th>
        <th class="c-rem">Remarks</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($sections as $section): ?>
        <?php if (! $matrix || count($sections) > 1): ?>
            <tr class="section-row"><td colspan="<?= $columns ?>"><?= e($section['title']) ?></td></tr>
        <?php endif ?>
        <?php foreach ($section['rows'] as $row): ?>
            <tr>
                <td class="c-sr"><?= (int) $row['sr'] ?></td>
                <td class="c-name"><?= e($row['name']) ?></td>
                <td class="c-spec"><?= e($row['spec']) ?></td>
                <td class="c-lim num"><?= e($row['lsl']) ?></td>
                <td class="c-lim num"><?= e($row['usl']) ?></td>
                <?php if ($matrix): ?>
                    <?php foreach ($methodColumns as $m): ?><td class="c-method"><?= $row['methodId'] === (int) $m['id'] ? '✓' : '' ?></td><?php endforeach ?>
                <?php else: ?>
                    <td class="c-unit"><?= e($row['unit']) ?></td>
                    <td class="c-method"><?= e($row['method']) ?></td>
                    <td class="c-inst"><?= e($row['instrument']) ?></td>
                <?php endif ?>
                <td class="c-gauge"><?= e($row['gauge']) ?><?= $row['gaugeOk'] ? '' : ' <b>(cal. expired)</b>' ?></td>
                <?php for ($n = 0; $n < $maxObs; $n++): ?>
                    <?php if ($n >= $row['obsCount']): ?><td class="c-obs na"></td><?php continue; endif ?>
                    <?php $reading = $row['readings'][$n]; ?>
                    <td class="c-obs num<?= $reading['fail'] ? ' oos' : '' ?>"><?= e($reading['text']) ?><?= $reading['fail'] ? ' ✗' : '' ?></td>
                <?php endfor ?>
                <td class="c-res<?= $row['result'] === 'FAIL' ? ' oos' : '' ?>"><?= match ($row['result']) { 'PASS' => 'PASS', 'FAIL' => 'FAIL', 'NOT_APPLICABLE' => 'N/A', 'INCOMPLETE' => '—', default => '' } ?></td>
                <td class="c-rem"><?= e($row['remarks']) ?></td>
            </tr>
        <?php endforeach ?>
    <?php endforeach ?>
    </tbody>
</table>
