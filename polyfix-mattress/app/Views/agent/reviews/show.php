<?= $this->extend('layouts/floor') ?>

<?php
/**
 * One warranty review, and everything that has happened on it.
 *
 * @var array<string,mixed>       $c
 * @var list<array<string,mixed>> $events
 * @var array<string,string>      $reasons
 */
$tone = [
    'RECOMMENDED'           => 'warning',
    'UNDER_REVIEW'          => 'info',
    'INFORMATION_REQUESTED' => 'info',
    'UPHELD'                => 'danger',
    'DISMISSED'             => 'success',
    'WITHDRAWN'             => 'secondary',
];
$stillOpen = in_array($c['status'], ['RECOMMENDED', 'UNDER_REVIEW', 'INFORMATION_REQUESTED'], true);
?>

<?= $this->section('content') ?>

<div class="page-head">
    <h1 class="mono"><?= esc($c['reference']) ?></h1>
    <p><?= esc($c['dealer']) ?> · raised <?= esc(local_date($c['raised_at'])) ?></p>
</div>

<div class="panel mb-3">
    <div class="panel-head">
        <strong class="mono"><?= esc($c['serial_number']) ?></strong>
        <span class="badge text-bg-<?= $tone[$c['status']] ?? 'secondary' ?>"><?= esc(humanise($c['status'])) ?></span>
    </div>
    <div class="p-3">
        <dl class="row mb-0 small">
            <dt class="col-5">Model</dt><dd class="col-7"><?= esc($c['product_name']) ?> · <?= esc($c['size_label']) ?></dd>
            <dt class="col-5">Reason given</dt><dd class="col-7"><?= esc($reasons[$c['reason']] ?? $c['reason']) ?></dd>
            <?php if ($c['agent_remark'] !== null): ?>
                <dt class="col-5">My remark</dt><dd class="col-7">“<?= esc($c['agent_remark']) ?>”</dd>
            <?php endif ?>
            <dt class="col-5">Invoice</dt>
            <dd class="col-7"><?= $c['invoice_number'] !== null
                ? '<span class="mono">' . esc($c['invoice_number']) . '</span> · ' . esc(local_date($c['sale_date']))
                : '<span class="text-warning">no sale recorded</span>' ?></dd>
            <dt class="col-5">Warranty</dt>
            <dd class="col-7"><?= $c['warranty_status'] !== null
                ? esc(humanise($c['warranty_status'])) . ' to ' . esc(local_date($c['warranty_end_date']))
                : 'none on record' ?></dd>
            <?php if ($c['survey_reference'] !== null): ?>
                <dt class="col-5">From survey</dt><dd class="col-7 mono"><?= esc($c['survey_reference']) ?></dd>
            <?php endif ?>
        </dl>
    </div>
</div>

<?php if ($c['decided_at'] !== null): ?>
    <div class="alert alert-<?= $c['status'] === 'UPHELD' ? 'danger' : 'success' ?>">
        <strong><?= $c['status'] === 'UPHELD' ? 'Warranty rejected' : 'Recommendation dismissed' ?></strong>
        <span class="d-block small"><?= esc($c['decision_reason']) ?></span>
        <span class="d-block small text-muted">
            <?= esc($c['decided_by_name'] ?? 'the office') ?> · <?= esc(local_date($c['decided_at'])) ?>
        </span>
    </div>
<?php endif ?>

<h2 class="h2 mb-2">History</h2>
<div class="panel mb-3">
    <ul class="list-unstyled mb-0">
        <?php foreach ($events as $event): ?>
            <li class="p-3 border-bottom">
                <div class="d-flex justify-content-between gap-2">
                    <strong><?= esc(humanise($event['event'])) ?></strong>
                    <span class="small text-muted text-nowrap"><?= esc(local_date($event['occurred_at'])) ?></span>
                </div>
                <?php if ($event['note'] !== null): ?>
                    <div class="small mt-1"><?= nl2br(esc($event['note'])) ?></div>
                <?php endif ?>
                <div class="small text-muted"><?= esc($event['actor'] ?? 'system') ?></div>
            </li>
        <?php endforeach ?>
    </ul>
</div>

<?php if ($stillOpen && $c['raised_by'] === $ctx->userId()): ?>
    <details class="panel p-3">
        <summary>Withdraw this recommendation</summary>
        <form class="mt-3" method="post" action="<?= site_url('agent/reviews/' . $c['id'] . '/withdraw') ?>">
            <?= csrf_field() ?>
            <label class="form-label" for="reason">Why?</label>
            <textarea class="form-control mb-2" id="reason" name="reason" rows="2" maxlength="500" required
                      placeholder="e.g. the dealer produced the invoice while I was there"></textarea>
            <button class="btn btn-light btn-floor w-100" type="submit">Withdraw</button>
        </form>
    </details>
<?php endif ?>

<?= $this->endSection() ?>
