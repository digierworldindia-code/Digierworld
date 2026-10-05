<?php
/**
 * Workflow progress: Submitted → Production → Quality → QA with signer, date and time. Variable: $steps.
 */
defined('QMS') || exit;

$flowIcons = ['done' => 'bi-check-circle-fill', 'current' => 'bi-hourglass-split', 'returned' => 'bi-arrow-return-left', 'rejected' => 'bi-x-octagon-fill', 'todo' => 'bi-circle'];
$flowTexts = ['done' => 'Signed', 'current' => 'Waiting', 'returned' => 'Returned', 'rejected' => 'Rejected', 'todo' => 'Not yet'];
?>
<ol class="qms-flow" aria-label="Approval progress">
    <?php foreach ($steps as $step): $sig = $step['signature']; ?>
        <li class="qms-flow-step is-<?= e($step['state']) ?>"<?= $step['state'] === 'current' ? ' aria-current="step"' : '' ?>>
            <?= qms_icon($flowIcons[$step['state']] ?? 'bi-circle') ?>
            <div class="min-w-0">
                <strong><?= e($step['label']) ?></strong>
                <span class="visually-hidden">: <?= e($flowTexts[$step['state']] ?? '') ?></span>
                <div class="small text-muted"><?= e($step['title']) ?></div>
                <?php if ($sig !== null): ?>
                    <div class="small"><?= e($sig['full_name'] ?? $sig['username']) ?> · <?= e(plant_dt($sig['signed_at'])) ?></div>
                <?php elseif ($step['state'] === 'current'): ?>
                    <div class="small text-muted">Waiting</div>
                <?php endif ?>
            </div>
        </li>
    <?php endforeach ?>
</ol>
