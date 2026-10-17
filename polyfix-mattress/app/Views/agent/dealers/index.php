<?= $this->extend('layouts/floor') ?>

<?php
/**
 * The dealers this agent covers, soonest due first.
 *
 * @var list<array<string,mixed>> $assignments
 */
$due = [
    'OVERDUE'   => ['danger', 'Overdue'],
    'DUE'       => ['warning', 'Due now'],
    'UPCOMING'  => ['info', 'Soon'],
    'SCHEDULED' => ['secondary', 'Scheduled'],
];
?>

<?= $this->section('content') ?>

<div class="page-head">
    <h1>My dealers</h1>
    <p><?= count($assignments) ?> assigned to me.</p>
</div>

<?php if ($assignments === []): ?>
    <div class="panel p-4 text-center text-muted">
        No dealers are assigned to you yet. The office sets this up.
    </div>
<?php else: ?>
    <div class="panel">
        <ul class="list-unstyled mb-0">
            <?php foreach ($assignments as $row): ?>
                <?php [$tone, $label] = $due[$row['due_state']] ?? ['secondary', 'Scheduled'] ?>
                <li class="border-bottom">
                    <a class="d-block p-3 text-decoration-none text-body" href="<?= site_url('agent/dealers/' . $row['dealer_id']) ?>">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <strong><?= esc($row['business_name']) ?></strong>
                                <span class="d-block small text-muted mono"><?= esc($row['dealer_code']) ?></span>
                            </div>
                            <span class="badge text-bg-<?= $tone ?>"><?= esc($label) ?></span>
                        </div>
                        <div class="small text-muted mt-1">
                            <?= esc(trim(($row['city'] ?? '') . ', ' . ($row['state'] ?? ''), ', ')) ?>
                            <?php if ($row['last_surveyed_on'] !== null): ?>
                                · last seen <?= esc(local_date($row['last_surveyed_on'])) ?>
                            <?php endif ?>
                            <?php if ($row['open_survey_id'] !== null): ?>
                                <span class="d-block text-warning">Survey in progress — tap to carry on</span>
                            <?php endif ?>
                        </div>
                    </a>
                </li>
            <?php endforeach ?>
        </ul>
    </div>
<?php endif ?>

<?= $this->endSection() ?>
