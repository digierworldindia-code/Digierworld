<?php
/**
 * In-Process Inspection Report grid: shifts A / B / C with their inspection times.
 *
 * @var list<array<string, mixed>> $gridShifts
 * @var list<array<string, mixed>> $gridRows
 * @var array<string, int>         $totals
 */
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
            <th colspan="<?= count($group['columns']) ?>">Shift <?= esc((string) $group['shift']['code']) ?></th>
        <?php endforeach ?>
    </tr>
    <tr>
        <?php foreach ($gridShifts as $group): ?>
            <?php foreach ($group['columns'] as $round): ?>
                <th class="g-cell"><?= $round === null ? 'Time' : esc(substr((string) $round['inspection_time'], 0, 5)) ?></th>
            <?php endforeach ?>
        <?php endforeach ?>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($gridRows as $row): ?>
        <tr>
            <td class="c-sr"><?= (int) $row['sr'] ?></td>
            <td class="c-name"><?= esc($row['name']) ?></td>
            <td class="c-spec"><?= esc($row['spec']) ?><?= $row['limits'] !== '' ? '<br>' . esc($row['limits']) : '' ?></td>
            <td class="c-method"><?= esc($row['method']) ?></td>
            <?php foreach ($gridShifts as $group): ?>
                <?php foreach ($group['columns'] as $round): ?>
                    <?php if ($round === null): ?><td class="g-cell"></td><?php continue; endif ?>
                    <?php $readings = $row['cells'][(int) $round['id']] ?? []; ?>
                    <td class="g-cell num">
                        <?php foreach ($readings as $i => $reading): ?><?= $i > 0 ? '<br>' : '' ?><span class="<?= $reading['fail'] ? 'oos' : '' ?>"><?= esc($reading['text']) ?><?= $reading['fail'] ? ' ✗' : '' ?></span><?php endforeach ?>
                    </td>
                <?php endforeach ?>
            <?php endforeach ?>
        </tr>
    <?php endforeach ?>
    </tbody>
    <tbody class="grid-foot">
    <tr><th colspan="<?= $fixed ?>">Lot status</th><?= $foot(static fn (array $r): string => esc(ucwords(strtolower(str_replace('_', ' ', (string) $r['lot_status']))))) ?></tr>
    <tr><th colspan="<?= $fixed ?>">Accepted (total <?= (int) $totals['accepted'] ?>)</th><?= $foot(static fn (array $r): string => $r['accepted_qty'] === null ? '' : (string) (int) $r['accepted_qty']) ?></tr>
    <tr><th colspan="<?= $fixed ?>">Rejected (total <?= (int) $totals['rejected'] ?>)</th><?= $foot(static fn (array $r): string => $r['rejected_qty'] === null ? '' : (string) (int) $r['rejected_qty']) ?></tr>
    <tr><th colspan="<?= $fixed ?>">Rework (total <?= (int) $totals['rework'] ?>)</th><?= $foot(static fn (array $r): string => $r['rework_qty'] === null ? '' : (string) (int) $r['rework_qty']) ?></tr>
    <tr><th colspan="<?= $fixed ?>">Inspected by operator</th><?= $foot(static fn (array $r): string => $r['status'] === 'OPEN' ? '' : esc((string) ($r['operator_name'] ?? $r['operator_username'])) . '<br><small>' . esc(plant_dt($r['operator_signed_at'], 'd-m H:i')) . '</small>') ?></tr>
    <tr><th colspan="<?= $fixed ?>">Inspected by QE</th><?= $foot(static fn (array $r): string => $r['status'] !== 'VERIFIED' ? '' : esc((string) ($r['qe_name'] ?? $r['qe_username'])) . '<br><small>' . esc(plant_dt($r['qe_verified_at'], 'd-m H:i')) . '</small>') ?></tr>
    </tbody>
</table>
