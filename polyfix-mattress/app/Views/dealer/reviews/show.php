<?= $this->extend('layouts/portal') ?>
<?= $this->section('content') ?>
<?php
/**
 * One review, as the dealer sees it: what was questioned, where it has got
 * to, and the one thing they can do about it.
 *
 * @var array<string,mixed>       $c
 * @var list<array<string,mixed>> $events
 * @var array<string,string>      $reasons
 */
$open = in_array($c['status'], ['RECOMMENDED', 'UNDER_REVIEW', 'INFORMATION_REQUESTED'], true);
?>
<div class="page-head">
    <div class="small"><a href="<?= site_url('dealer/reviews') ?>">Warranty reviews</a></div>
    <h1 class="mono"><?= esc($c['reference']) ?></h1>
    <p>Opened <?= esc(local_date($c['raised_at'])) ?></p>
</div>

<?php if ($c['status'] === 'UPHELD'): ?>
    <div class="alert alert-danger">
        <strong>This warranty has been rejected.</strong>
        <span class="d-block small"><?= esc($c['decision_reason']) ?></span>
        <span class="d-block small mt-1">If you think this is wrong, contact <?= esc(brand('shortName')) ?> with the invoice.</span>
    </div>
<?php elseif ($c['status'] === 'DISMISSED'): ?>
    <div class="alert alert-success">
        <strong>The warranty stands.</strong>
        <span class="d-block small"><?= esc($c['decision_reason']) ?></span>
    </div>
<?php elseif ($c['status'] === 'INFORMATION_REQUESTED'): ?>
    <div class="alert alert-warning">
        <strong>We need something from you.</strong>
        <span class="d-block small"><?= esc($c['dealer_visible_note']) ?></span>
    </div>
<?php else: ?>
    <div class="alert alert-info">
        <strong>Being looked at.</strong>
        <span class="d-block small">The customer's warranty is unchanged while this is open.</span>
    </div>
<?php endif ?>

<div class="panel mb-3">
    <div class="panel-head">
        <strong class="mono"><?= esc($c['serial_number']) ?></strong>
    </div>
    <div class="p-3">
        <dl class="row mb-0 small">
            <dt class="col-5">Model</dt><dd class="col-7"><?= esc($c['product_name']) ?> · <?= esc($c['size_label']) ?></dd>
            <dt class="col-5">Question raised</dt><dd class="col-7"><?= esc($reasons[$c['reason']] ?? $c['reason']) ?></dd>
            <dt class="col-5">Your invoice</dt>
            <dd class="col-7"><?= $c['invoice_number'] !== null
                ? '<span class="mono">' . esc($c['invoice_number']) . '</span> · ' . esc(local_date($c['sale_date']))
                : '<span class="text-warning">no sale recorded against this mattress</span>' ?></dd>
            <dt class="col-5">Warranty</dt>
            <dd class="col-7"><?= $c['warranty_status'] !== null
                ? esc(humanise($c['warranty_status'])) . ' to ' . esc(local_date($c['warranty_end_date']))
                : 'none on record' ?></dd>
        </dl>
    </div>
</div>

<?php if ($open): ?>
    <div class="panel mb-3">
        <div class="panel-head"><strong>Upload the invoice</strong></div>
        <form class="p-3" method="post" action="<?= site_url('dealer/reviews/' . $c['id'] . '/invoice') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <label class="form-label" for="invoice">Invoice file</label>
            <input class="form-control mb-2" type="file" id="invoice" name="invoice"
                   accept="application/pdf,image/jpeg,image/png,image/webp" required>
            <div class="form-text mb-2">A PDF or a clear photograph of the customer's invoice.</div>

            <label class="form-label" for="note">Anything to add <span class="text-muted">(optional)</span></label>
            <textarea class="form-control mb-2" id="note" name="note" rows="2" maxlength="500"></textarea>

            <button class="btn btn-primary w-100" type="submit">Send it</button>
        </form>
    </div>
<?php endif ?>

<h2 class="h2 mb-2">What has happened</h2>
<div class="panel">
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
            </li>
        <?php endforeach ?>
    </ul>
</div>
<?= $this->endSection() ?>
