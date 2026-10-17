<?= $this->extend('layouts/floor') ?>

<?php
/**
 * Every survey this agent has done. Each visit is its own row and its own
 * report; nothing is overwritten by the next one.
 *
 * @var list<array<string,mixed>> $rows
 */
?>

<?= $this->section('content') ?>

<div class="page-head">
    <h1>My surveys</h1>
    <p>Each visit keeps its own report.</p>
</div>

<?php if ($rows === []): ?>
    <div class="panel p-4 text-center text-muted">
        No surveys yet. Open one from a dealer on your round.
    </div>
<?php else: ?>
    <div class="panel">
        <ul class="list-unstyled mb-0">
            <?php foreach ($rows as $row): ?>
                <li class="border-bottom">
                    <a class="d-block p-3 text-decoration-none text-body"
                       href="<?= site_url('agent/surveys/' . $row['id'] . ($row['status'] === 'IN_PROGRESS' ? '' : '/report')) ?>">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <strong><?= esc($row['dealer']) ?></strong>
                                <span class="d-block small text-muted mono"><?= esc($row['reference']) ?></span>
                            </div>
                            <?= pill($row['status']) ?>
                        </div>
                        <div class="small text-muted mt-1">
                            <?= esc(local_date($row['submitted_at'] ?? $row['started_at'])) ?>
                            <?php if ($row['status'] !== 'IN_PROGRESS'): ?>
                                · <?= (int) $row['physically_found'] ?> of <?= (int) $row['system_total'] ?> found
                                <?php if ((int) $row['discrepancy_count'] > 0): ?>
                                    · <span class="text-danger"><?= (int) $row['discrepancy_count'] ?> to look into</span>
                                <?php endif ?>
                            <?php endif ?>
                        </div>
                    </a>
                </li>
            <?php endforeach ?>
        </ul>
    </div>
<?php endif ?>

<?= $this->endSection() ?>
