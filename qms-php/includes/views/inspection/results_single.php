<?php
/**
 * Read-only results of a single-round report, section by section. Variables: $structure, $rounds.
 */
defined('QMS') || exit;

$round  = $rounds[0] ?? ['observations' => []];
$maxObs = 1;
foreach ($structure as $section) {
    foreach ($section['parameters'] as $param) {
        $maxObs = max($maxObs, (int) $param['observation_count']);
    }
}
$sr = 0;
?>
<div class="qms-table-wrap">
    <table class="table table-sm qms-results">
        <thead>
        <tr>
            <th>#</th><th>Parameter</th><th>Specification</th><th>Method / instrument</th><th>Gauge ID</th>
            <?php for ($n = 1; $n <= $maxObs; $n++): ?><th class="text-center">Obs <?= $n ?></th><?php endfor ?>
            <th>Result</th><th>Remarks</th>
        </tr>
        </thead>
        <?php foreach ($structure as $section): ?>
            <tbody>
            <tr class="qms-results-section"><th colspan="<?= 7 + $maxObs ?>"><?= e($section['title']) ?></th></tr>
            <?php foreach ($section['parameters'] as $param): $sr++; $obs = $round['observations'][(int) $param['id']] ?? null; ?>
                <tr<?= ($obs['result'] ?? '') === 'FAIL' ? ' class="is-fail"' : '' ?>>
                    <td><?= $sr ?></td>
                    <td class="fw-semibold"><?= e($param['name']) ?></td>
                    <td class="small"><?= e((string) ($param['specification_text'] ?? '')) ?>
                        <?php $lim = spec_limits($obs['lsl'] ?? $param['lsl'], $obs['usl'] ?? $param['usl'], (int) $param['decimal_places'], $param['observation_type'] === 'PERCENTAGE' ? '%' : $param['unit_symbol']); ?>
                        <?= $lim !== '' ? '<div class="text-muted">' . e($lim) . '</div>' : '' ?></td>
                    <td class="small"><?= e((string) ($param['method_label'] ?? '')) ?><?= $param['gauge_type_name'] !== null ? '<div class="text-muted">' . e($param['gauge_type_name']) . '</div>' : '' ?></td>
                    <td class="small"><?= e((string) ($obs['gauge_code'] ?? '')) ?>
                        <?php if ($obs !== null && $obs['gauge_id'] !== null && (int) $obs['gauge_cal_valid'] === 0): ?><div class="text-danger fw-bold"><?= qms_icon('bi-exclamation-triangle-fill') ?> Out of calibration</div><?php endif ?></td>
                    <?php for ($n = 1; $n <= $maxObs; $n++): ?>
                        <?php if ($n > (int) $param['observation_count']): ?><td class="qms-na"></td><?php continue; endif ?>
                        <?php $reading = $obs['readings'][$n] ?? null; $fail = ($reading['result'] ?? null) === 'FAIL'; ?>
                        <td class="text-center num text-nowrap<?= $fail ? ' qms-oos' : '' ?>"><?= e(reading_text($param, $reading)) ?><?= $fail ? ' <span class="qms-oos-mark">✗</span>' : '' ?></td>
                    <?php endfor ?>
                    <td><?= result_badge($obs['result'] ?? null) ?></td>
                    <td class="small"><?= e((string) ($obs['remarks'] ?? '')) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        <?php endforeach ?>
    </table>
</div>
