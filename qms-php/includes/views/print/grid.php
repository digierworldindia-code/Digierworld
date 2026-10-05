<?php
/**
 * In-Process Inspection Report grid: shifts A / B / C with their inspection times.
 * Variables: $gridShifts, $gridRows, $totals.
 */
defined('QMS') || exit;

$fixed = 4;
$foot  = static function (callable $cell) use ($gridShifts): string {
    $html = '';
    foreach ($gridShifts as $group) {
        foreach ($group['columns'] as $round) {
            $html .= '<td class="g-cell">' . ($round === null ? '' : $cell($round)) . '</td>';
        }
    }

    return $html;
};
?>
<table class="grid">
    <thead>
    <tr>
        <th class="c-sr" rowspan="2">Sr.</th>
        <th class="c-name" rowspan="2">Parameter</th>
        <th class="c-spec" rowspan="2">Specification</th>
        <th class="c-method" rowspan="2">Method</th>
        <?php foreach ($gridShifts as $group): ?>
            <th colspan="<?= count($group['columns']) ?>">Shift <?= e((string) $group['shift']['code']) ?></th>
        <?php endforeach ?>
    </tr>
    <tr>
        <?php foreach ($gridShifts as $group): ?>
            <?php foreach ($group['columns'] as $round): ?>
                <th class="g-cell"><?= $round === null ? 'Time' : e(substr((string) $round['inspection_time'], 0, 5)) ?></th>
            <?php endforeach ?>
        <?php endforeach ?>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($gridRows as $row): ?>
        <tr>
            <td class="c-sr"><?= (int) $row['sr'] ?></td>
            <td class="c-name"><?= e($row['name']) ?></td>
            <td class="c-spec"><?= e($row['spec']) ?><?= $row['limits'] !== '' ? '<br>' . e($row['limits']) : '' ?></td>
            <td class="c-method"><?= e($row['method']) ?></td>
            <?php foreach ($gridShifts as $group): ?>
                <?php foreach ($group['columns'] as $round): ?>
                    <?php if ($round === null): ?><td class="g-cell"></td><?php continue; endif ?>
                    <td class="g-cell num">
                        <?php foreach ($row['cells'][(int) $round['id']] ?? [] as $i => $reading): ?><?= $i > 0 ? '<br>' : '' ?><span class="<?= $reading['fail'] ? 'oos' : '' ?>"><?= e($reading['text']) ?><?= $reading['fail'] ? ' ✗' : '' ?></span><?php endforeach ?>
                    </td>
                <?php endforeach ?>
            <?php endforeach ?>
        </tr>
    <?php endforeach ?>
    </tbody>
    <tbody class="grid-foot">
    <tr><th colspan="<?= $fixed ?>">Lot status</th><?= $foot(static fn (array $r): string => e(LOT_STATUSES[$r['lot_status']] ?? '')) ?></tr>
    <tr><th colspan="<?= $fixed ?>">Accepted (total <?= (int) $totals['accepted'] ?>)</th><?= $foot(static fn (array $r): string => $r['accepted_qty'] === null ? '' : (string) (int) $r['accepted_qty']) ?></tr>
    <tr><th colspan="<?= $fixed ?>">Rejected (total <?= (int) $totals['rejected'] ?>)</th><?= $foot(static fn (array $r): string => $r['rejected_qty'] === null ? '' : (string) (int) $r['rejected_qty']) ?></tr>
    <tr><th colspan="<?= $fixed ?>">Rework (total <?= (int) $totals['rework'] ?>)</th><?= $foot(static fn (array $r): string => $r['rework_qty'] === null ? '' : (string) (int) $r['rework_qty']) ?></tr>
    <tr><th colspan="<?= $fixed ?>">Inspected by operator</th><?= $foot(static fn (array $r): string => $r['status'] === 'OPEN' ? '' : e((string) ($r['operator_name'] ?? $r['operator_username'])) . '<br><small>' . e(plant_dt($r['operator_signed_at'], 'd-m H:i')) . '</small>') ?></tr>
    <tr><th colspan="<?= $fixed ?>">Inspected by QE</th><?= $foot(static fn (array $r): string => $r['status'] !== 'VERIFIED' ? '' : e((string) ($r['qe_name'] ?? $r['qe_username'])) . '<br><small>' . e(plant_dt($r['qe_verified_at'], 'd-m H:i')) . '</small>') ?></tr>
    </tbody>
</table>
