<?= $this->extend('layouts/floor') ?>

<?php
/**
 * One dealer: every mattress the records place with them, with whatever is
 * known about its sale and warranty, and the filters to find one quickly on
 * a phone in a showroom.
 *
 * Every number here is counted from the database. None of it is typed into
 * the template, because an agent checking a shelf against a stale figure
 * would raise discrepancies that are not real.
 *
 * @var array<string,mixed>       $dealer
 * @var array<string,int>         $totals
 * @var list<array<string,mixed>> $rows
 * @var array<string,mixed>       $filters
 * @var array<string,mixed>|null  $open
 * @var list<array<string,mixed>> $history
 * @var list<array<string,mixed>> $models
 * @var list<array<string,mixed>> $sizes
 */
$statuses = [
    'DEALER_RECEIVED' => 'In stock',
    'DISPATCHED'      => 'In transit',
    'SOLD'            => 'Sold',
    'CLAIM_OPEN'      => 'Claim open',
    'REPLACED'        => 'Replaced',
    'RETURNED'        => 'Returned',
    'SCRAPPED'        => 'Scrapped',
];
?>

<?= $this->section('content') ?>

<div class="page-head">
    <h1><?= esc($dealer['business_name']) ?></h1>
    <p><span class="mono"><?= esc($dealer['code']) ?></span>
        · <?= esc(trim(($dealer['city'] ?? '') . ', ' . ($dealer['state'] ?? ''), ', ')) ?></p>
</div>

<div class="row g-2 mb-3">
    <div class="col-6 col-sm-3"><div class="stat"><div class="stat__label">Total</div><div class="stat__value"><?= $totals['total'] ?></div></div></div>
    <div class="col-6 col-sm-3"><div class="stat"><div class="stat__label">In stock</div><div class="stat__value"><?= $totals['available'] ?></div></div></div>
    <div class="col-6 col-sm-3"><div class="stat"><div class="stat__label">Sold</div><div class="stat__value"><?= $totals['sold'] ?></div></div></div>
    <div class="col-6 col-sm-3"><div class="stat"><div class="stat__label">In transit</div><div class="stat__value"><?= $totals['in_transit'] ?></div></div></div>
</div>

<div class="floor-menu mb-3">
    <form method="post" action="<?= site_url('agent/dealers/' . $dealer['id'] . '/survey') ?>" class="d-contents">
        <?= csrf_field() ?>
        <button class="floor-action w-100 text-start border-0" type="submit">
            <i class="bi bi-clipboard-check" aria-hidden="true"></i>
            <span>
                <strong><?= $open !== null ? 'Carry on with the survey' : 'Start stock survey' ?></strong>
                <span><?= $open !== null ? esc($open['reference']) : 'Check the shelf against the records' ?></span>
            </span>
        </button>
    </form>
</div>

<!-- Filters. Folded away by default: on a phone the list is what matters
     and four selects above it would push everything off the screen. -->
<details class="panel p-3 mb-3"<?= array_filter($filters) !== [] ? ' open' : '' ?>>
    <summary class="fw-semibold">Filter<?= array_filter($filters) !== [] ? ' (on)' : '' ?></summary>
    <form class="row g-2 mt-1" method="get" action="<?= site_url('agent/dealers/' . $dealer['id']) ?>">
        <div class="col-12">
            <label class="form-label" for="q">Serial or model</label>
            <input class="form-control" type="search" id="q" name="q" value="<?= esc($filters['q'], 'attr') ?>" maxlength="60">
        </div>
        <div class="col-6">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">Any</option>
                <?php foreach ($statuses as $key => $label): ?>
                    <option value="<?= $key ?>"<?= $filters['status'] === $key ? ' selected' : '' ?>><?= esc($label) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="col-6">
            <label class="form-label" for="warranty">Warranty</label>
            <select class="form-select" id="warranty" name="warranty">
                <option value="">Any</option>
                <option value="ACTIVE"<?= $filters['warranty'] === 'ACTIVE' ? ' selected' : '' ?>>Active</option>
                <option value="NONE"<?= $filters['warranty'] === 'NONE' ? ' selected' : '' ?>>None</option>
            </select>
        </div>
        <div class="col-6">
            <label class="form-label" for="model">Model</label>
            <select class="form-select" id="model" name="model">
                <option value="">Any</option>
                <?php foreach ($models as $model): ?>
                    <option value="<?= esc($model['id'], 'attr') ?>"<?= $filters['model'] === $model['id'] ? ' selected' : '' ?>><?= esc($model['name']) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="col-6">
            <label class="form-label" for="size">Size</label>
            <select class="form-select" id="size" name="size">
                <option value="">Any</option>
                <?php foreach ($sizes as $size): ?>
                    <option value="<?= esc($size['id'], 'attr') ?>"<?= $filters['size'] === $size['id'] ? ' selected' : '' ?>>
                        <?= esc($size['product']) ?> · <?= esc($size['size_label']) ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="col-6">
            <label class="form-label" for="from">Dispatched from</label>
            <input class="form-control" type="date" id="from" name="from" value="<?= esc($filters['from'], 'attr') ?>">
        </div>
        <div class="col-6">
            <label class="form-label" for="to">to</label>
            <input class="form-control" type="date" id="to" name="to" value="<?= esc($filters['to'], 'attr') ?>">
        </div>
        <div class="col-12 d-flex gap-2">
            <button class="btn btn-primary flex-grow-1" type="submit">Apply</button>
            <a class="btn btn-light" href="<?= site_url('agent/dealers/' . $dealer['id']) ?>">Clear</a>
        </div>
    </form>
</details>

<h2 class="h2 mb-2">Stock <span class="text-muted fw-normal">(<?= count($rows) ?>)</span></h2>

<?php if ($rows === []): ?>
    <div class="panel p-4 text-center text-muted">Nothing matches those filters.</div>
<?php else: ?>
    <div class="panel">
        <ul class="list-unstyled mb-0">
            <?php foreach ($rows as $row): ?>
                <li class="p-3 border-bottom">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="min-w-0">
                            <span class="mono fw-semibold"><?= esc($row['serial_number']) ?></span>
                            <span class="d-block small"><?= esc($row['product_name']) ?> · <?= esc($row['size_label']) ?></span>
                        </div>
                        <?= pill($row['current_status'], $statuses[$row['current_status']] ?? null) ?>
                    </div>
                    <div class="small text-muted mt-1">
                        <?php if ($row['dispatch_code'] !== null): ?>
                            Sent <?= esc(local_date($row['dispatched_at'])) ?> on <?= esc($row['dispatch_code']) ?>.
                        <?php endif ?>
                        <?php if ($row['received_at'] !== null): ?>
                            Received <?= esc(local_date($row['received_at'])) ?>.
                        <?php endif ?>
                        <?php if ($row['sale_id'] !== null): ?>
                            <span class="d-block">Sold <?= esc(local_date($row['sale_date'])) ?>
                                on invoice <span class="mono"><?= esc($row['invoice_number']) ?></span>.</span>
                        <?php endif ?>
                        <?php if ($row['warranty_id'] !== null): ?>
                            <span class="d-block">Warranty <?= esc(strtolower(humanise($row['warranty_status']))) ?>
                                to <?= esc(local_date($row['warranty_expires_on'])) ?>.</span>
                        <?php elseif ($row['sale_id'] !== null): ?>
                            <span class="d-block text-warning">Sold with no warranty recorded.</span>
                        <?php endif ?>
                    </div>
                    <?php if ($ctx->can('warranty_review:recommend') && $row['sale_id'] !== null): ?>
                        <a class="small" href="<?= site_url('agent/reviews/new?code=' . rawurlencode($row['serial_number'])) ?>">
                            Report a warranty problem
                        </a>
                    <?php endif ?>
                </li>
            <?php endforeach ?>
        </ul>
    </div>
<?php endif ?>

<?php if ($history !== []): ?>
    <h2 class="h2 mt-4 mb-2">Past surveys</h2>
    <div class="panel">
        <ul class="list-unstyled mb-0">
            <?php foreach ($history as $past): ?>
                <li class="border-bottom">
                    <a class="d-block p-3 text-decoration-none text-body" href="<?= site_url('agent/surveys/' . $past['id'] . '/report') ?>">
                        <span class="mono fw-semibold"><?= esc($past['reference']) ?></span>
                        <span class="d-block small text-muted">
                            <?= esc(local_date($past['submitted_at'] ?? $past['started_at'])) ?>
                            · <?= esc(strtolower(humanise($past['status']))) ?>
                            <?php if ((int) $past['discrepancy_count'] > 0): ?>
                                · <?= (int) $past['discrepancy_count'] ?> to look into
                            <?php endif ?>
                        </span>
                    </a>
                </li>
            <?php endforeach ?>
        </ul>
    </div>
<?php endif ?>

<?= $this->endSection() ?>
