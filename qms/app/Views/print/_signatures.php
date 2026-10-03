<?php
/**
 * Approval block: name, designation, date and time of the latest signature per stage.
 *
 * @var list<array<string, mixed>> $steps
 */
?>
<table class="sign">
    <tr>
        <?php foreach ($steps as $step): ?><th><?= esc($step['title']) ?><br><small><?= esc($step['label']) ?></small></th><?php endforeach ?>
    </tr>
    <tr>
        <?php foreach ($steps as $step): $sig = $step['signature']; ?>
            <td>
                <?php if ($sig !== null && $step['state'] === 'done'): ?>
                    <div class="sig-name"><?= esc($sig['full_name'] ?? $sig['username']) ?></div>
                    <div class="sig-meta"><?= esc(trim(($sig['designation'] ?? '') . ($sig['employee_code'] ? ' · ' . $sig['employee_code'] : ''), ' ·')) ?></div>
                    <div class="sig-meta">e-signed <?= esc(plant_dt($sig['signed_at'], 'd-m-Y H:i')) ?></div>
                <?php elseif ($sig !== null): ?>
                    <div class="sig-name"><?= esc(ucfirst($step['state'])) ?>: <?= esc($sig['full_name'] ?? $sig['username']) ?></div>
                    <div class="sig-meta"><?= esc(plant_dt($sig['signed_at'], 'd-m-Y H:i')) ?></div>
                <?php else: ?>
                    <div class="sig-empty"><?= $step['state'] === 'current' ? 'Pending' : '' ?></div>
                <?php endif ?>
            </td>
        <?php endforeach ?>
    </tr>
</table>
