<?php
/**
 * Workflow progress: Submitted → Production → Quality → QA with signer, date and time.
 *
 * @var list<array<string, mixed>> $steps
 */
$icons = [
    'done' => 'bi-check-circle-fill', 'current' => 'bi-hourglass-split', 'returned' => 'bi-arrow-return-left',
    'rejected' => 'bi-x-octagon-fill', 'todo' => 'bi-circle',
];
$texts = ['done' => 'Signed', 'current' => 'Waiting', 'returned' => 'Returned', 'rejected' => 'Rejected', 'todo' => 'Not yet'];
?>
<ol class="qms-flow" aria-label="Approval progress">
    <?php foreach ($steps as $step): $sig = $step['signature']; ?>
        <li class="qms-flow-step is-<?= esc($step['state'], 'attr') ?>"<?= $step['state'] === 'current' ? ' aria-current="step"' : '' ?>>
            <?= qms_icon($icons[$step['state']] ?? 'bi-circle') ?>
            <div class="min-w-0">
                <strong><?= esc($step['label']) ?></strong>
                <span class="visually-hidden">: <?= esc($texts[$step['state']] ?? '') ?></span>
                <div class="small text-muted"><?= esc($step['title']) ?></div>
                <?php if ($sig !== null): ?>
                    <div class="small"><?= esc($sig['full_name'] ?? $sig['username']) ?> · <?= esc(plant_dt($sig['signed_at'])) ?></div>
                <?php elseif ($step['state'] === 'current'): ?>
                    <div class="small text-muted">Waiting</div>
                <?php endif ?>
            </div>
        </li>
    <?php endforeach ?>
</ol>
