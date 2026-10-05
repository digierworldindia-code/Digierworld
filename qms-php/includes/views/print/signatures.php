<?php
/**
 * Approval block: name, designation, date and time of the latest signature per stage. Variable: $steps.
 */
defined('QMS') || exit;
?>
<table class="sign">
    <tr>
        <?php foreach ($steps as $step): ?><th><?= e($step['title']) ?><br><small><?= e($step['label']) ?></small></th><?php endforeach ?>
    </tr>
    <tr>
        <?php foreach ($steps as $step): $sig = $step['signature']; ?>
            <td>
                <?php if ($sig !== null && $step['state'] === 'done'): ?>
                    <div class="sig-name"><?= e($sig['full_name'] ?? $sig['username']) ?></div>
                    <div class="sig-meta"><?= e(trim(($sig['designation'] ?? '') . ($sig['employee_code'] ? ' · ' . $sig['employee_code'] : ''), ' ·')) ?></div>
                    <div class="sig-meta">e-signed <?= e(plant_dt($sig['signed_at'], 'd-m-Y H:i')) ?></div>
                <?php elseif ($sig !== null): ?>
                    <div class="sig-name"><?= e(ucfirst($step['state'])) ?>: <?= e($sig['full_name'] ?? $sig['username']) ?></div>
                    <div class="sig-meta"><?= e(plant_dt($sig['signed_at'], 'd-m-Y H:i')) ?></div>
                <?php else: ?>
                    <div class="sig-empty"><?= $step['state'] === 'current' ? 'Pending' : '' ?></div>
                <?php endif ?>
            </td>
        <?php endforeach ?>
    </tr>
</table>
