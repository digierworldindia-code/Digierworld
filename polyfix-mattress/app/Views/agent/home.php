<?= $this->extend('layouts/floor') ?>

<?php
/**
 * The field agent's front screen: what is owed, what is in hand, what has
 * been raised.
 *
 * @var array<string,int>         $figures
 * @var list<array<string,mixed>> $assignments
 * @var string                    $agentCode
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
    <h1>My round</h1>
    <p>Agent <span class="mono"><?= esc($agentCode) ?></span></p>
</div>

<div class="floor-menu">
    <a class="floor-action" href="<?= site_url('agent/dealers') ?>">
        <i class="bi bi-shop" aria-hidden="true"></i>
        <span><strong>My dealers</strong><span><?= (int) $figures['due'] ?> due, <?= (int) $figures['upcoming'] ?> coming up</span></span>
        <span class="count"><?= (int) $figures['assigned_dealers'] ?></span>
    </a>

    <a class="floor-action<?= $figures['surveys_open'] === 0 ? ' floor-action--muted' : '' ?>" href="<?= site_url('agent/surveys') ?>">
        <i class="bi bi-clipboard-check" aria-hidden="true"></i>
        <span><strong>Surveys</strong><span><?= (int) $figures['surveys_open'] ?> in progress · <?= (int) $figures['surveys_completed'] ?> done</span></span>
    </a>

    <a class="floor-action<?= $figures['open_discrepancies'] === 0 ? ' floor-action--muted' : '' ?>" href="<?= site_url('agent/discrepancies') ?>">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <span><strong>Discrepancies</strong><span>Raised by me, still open</span></span>
        <span class="count"><?= (int) $figures['open_discrepancies'] ?></span>
    </a>

    <a class="floor-action<?= $figures['open_reviews'] === 0 ? ' floor-action--muted' : '' ?>" href="<?= site_url('agent/reviews') ?>">
        <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
        <span><strong>Warranty reviews</strong><span>Waiting on the office</span></span>
        <span class="count"><?= (int) $figures['open_reviews'] ?></span>
    </a>
</div>

<?php if ($assignments !== []): ?>
    <h2 class="h2 mt-4 mb-2">Next visits</h2>
    <div class="panel">
        <ul class="list-unstyled mb-0">
            <?php foreach ($assignments as $row): ?>
                <?php [$tone, $label] = $due[$row['due_state']] ?? ['secondary', 'Scheduled'] ?>
                <li class="border-bottom">
                    <a class="d-block p-3 text-decoration-none text-body" href="<?= site_url('agent/dealers/' . $row['dealer_id']) ?>">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <strong><?= esc($row['business_name']) ?></strong>
                                <span class="d-block small text-muted"><?= esc($row['city'] ?? '') ?></span>
                            </div>
                            <span class="badge text-bg-<?= $tone ?>"><?= esc($label) ?></span>
                        </div>
                        <div class="small text-muted mt-1">
                            <?= $row['next_due_on'] !== null
                                ? 'Due ' . esc(local_date($row['next_due_on']))
                                : 'Never surveyed' ?>
                            <?= $row['open_survey_id'] !== null ? ' · survey in progress' : '' ?>
                        </div>
                    </a>
                </li>
            <?php endforeach ?>
        </ul>
    </div>
<?php endif ?>

<?= $this->endSection() ?>
