<?= $this->extend('layouts/floor') ?>

<?php
/**
 * Consignments being picked, and the form to start another.
 *
 * @var list<array<string,mixed>> $drafts
 * @var list<array<string,mixed>> $dealers
 * @var list<array<string,mixed>> $warehouses
 */
?>

<?= $this->section('content') ?>

<div class="page-head">
    <h1>Prepare dispatch</h1>
    <p>Pick mattresses for a dealer by scanning or by ticking a batch.</p>
</div>

<?php if ($drafts !== []): ?>
    <h2 class="h2 mb-2">Being picked</h2>
    <div class="floor-menu mb-4">
        <?php foreach ($drafts as $draft): ?>
            <a class="floor-action" href="<?= site_url('warehouse/dispatch/' . $draft['id']) ?>">
                <i class="bi bi-cart" aria-hidden="true"></i>
                <span>
                    <strong><?= esc($draft['dealer']) ?></strong>
                    <span class="mono"><?= esc($draft['dispatch_code']) ?></span>
                </span>
                <span class="count"><?= (int) $draft['items'] ?></span>
            </a>
        <?php endforeach ?>
    </div>
<?php endif ?>

<h2 class="h2 mb-2">Start a new one</h2>

<?php if ($dealers === []): ?>
    <div class="panel p-4 text-muted">No active dealers. The office has to add one before stock can be sent.</div>
<?php else: ?>
<form class="panel p-3" method="post" action="<?= site_url('warehouse/dispatch') ?>">
    <?= csrf_field() ?>

    <div class="mb-3">
        <label class="form-label fw-semibold" for="dealer_id">Dealer</label>
        <select class="form-select form-select-lg" id="dealer_id" name="dealer_id" required>
            <option value="">Choose a dealer…</option>
            <?php foreach ($dealers as $dealer): ?>
                <option value="<?= esc($dealer['id'], 'attr') ?>">
                    <?= esc($dealer['business_name']) ?><?= $dealer['city'] !== null ? ' — ' . esc($dealer['city']) : '' ?>
                </option>
            <?php endforeach ?>
        </select>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold" for="warehouse_id">From plant</label>
        <select class="form-select form-select-lg" id="warehouse_id" name="warehouse_id" required>
            <?php foreach ($warehouses as $warehouse): ?>
                <option value="<?= esc($warehouse['id'], 'attr') ?>"><?= esc($warehouse['name']) ?></option>
            <?php endforeach ?>
        </select>
    </div>

    <button class="btn btn-primary btn-floor w-100" type="submit">
        <i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Start picking
    </button>
</form>
<?php endif ?>

<?= $this->endSection() ?>
