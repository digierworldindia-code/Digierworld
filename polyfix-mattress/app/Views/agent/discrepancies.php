<?= $this->extend('layouts/floor') ?>

<?php
/**
 * Discrepancies this agent raised, and where each one has got to.
 *
 * The agent cannot settle any of these; the screen is here so they can see
 * that what they reported was picked up, and what came of it.
 *
 * @var list<array<string,mixed>> $rows
 */
$tone = ['OPEN' => 'warning', 'UNDER_REVIEW' => 'info', 'RESOLVED' => 'success', 'DISMISSED' => 'secondary'];
?>

<?= $this->section('content') ?>

<div class="page-head">
    <h1>Discrepancies I raised</h1>
    <p>The office decides these. You will see the outcome here.</p>
</div>

<?php if ($rows === []): ?>
    <div class="panel p-4 text-center text-muted">Nothing raised yet.</div>
<?php else: ?>
    <div class="panel">
        <ul class="list-unstyled mb-0">
            <?php foreach ($rows as $row): ?>
                <li class="p-3 border-bottom">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <span class="mono fw-semibold"><?= esc($row['serial_number']) ?></span>
                            <span class="d-block small"><?= esc($row['dealer']) ?></span>
                        </div>
                        <span class="badge text-bg-<?= $tone[$row['status']] ?? 'secondary' ?>">
                            <?= esc(humanise($row['status'])) ?>
                        </span>
                    </div>
                    <div class="small text-muted mt-1">
                        <?= esc(humanise($row['kind'])) ?> ·
                        records said <?= esc(strtolower(humanise($row['system_status']))) ?>,
                        found <?= esc(strtolower(humanise($row['observation']))) ?>
                        · <?= esc(local_date($row['raised_at'])) ?>
                        <?php if ($row['survey_reference'] !== null): ?>
                            · <span class="mono"><?= esc($row['survey_reference']) ?></span>
                        <?php endif ?>
                    </div>
                    <?php if ($row['agent_remark'] !== null): ?>
                        <div class="small mt-1">“<?= esc($row['agent_remark']) ?>”</div>
                    <?php endif ?>
                    <?php if ($row['resolution_note'] !== null): ?>
                        <div class="small mt-1 text-success">Office: <?= esc($row['resolution_note']) ?></div>
                    <?php endif ?>
                </li>
            <?php endforeach ?>
        </ul>
    </div>
<?php endif ?>

<?= $this->endSection() ?>
