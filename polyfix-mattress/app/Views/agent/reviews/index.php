<?= $this->extend('layouts/floor') ?>

<?php
/**
 * Warranty reviews this agent has recommended.
 *
 * @var list<array<string,mixed>>  $rows
 * @var array<string,string>       $reasons
 */
$tone = [
    'RECOMMENDED'          => 'warning',
    'UNDER_REVIEW'         => 'info',
    'INFORMATION_REQUESTED' => 'info',
    'UPHELD'               => 'danger',
    'DISMISSED'            => 'success',
    'WITHDRAWN'            => 'secondary',
];
?>

<?= $this->section('content') ?>

<div class="page-head d-flex justify-content-between align-items-start gap-2">
    <div>
        <h1>Warranty reviews</h1>
        <p>You recommend; the office decides.</p>
    </div>
    <a class="btn btn-primary btn-floor" href="<?= site_url('agent/reviews/new') ?>">
        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New
    </a>
</div>

<?php if ($rows === []): ?>
    <div class="panel p-4 text-center text-muted">Nothing recommended yet.</div>
<?php else: ?>
    <div class="panel">
        <ul class="list-unstyled mb-0">
            <?php foreach ($rows as $row): ?>
                <li class="border-bottom">
                    <a class="d-block p-3 text-decoration-none text-body" href="<?= site_url('agent/reviews/' . $row['id']) ?>">
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
                            <span class="mono"><?= esc($row['reference']) ?></span>
                            · <?= esc($reasons[$row['reason']] ?? $row['reason']) ?>
                            · <?= esc(local_date($row['raised_at'])) ?>
                        </div>
                    </a>
                </li>
            <?php endforeach ?>
        </ul>
    </div>
<?php endif ?>

<?= $this->endSection() ?>
