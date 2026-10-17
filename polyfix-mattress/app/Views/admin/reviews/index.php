<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php
/**
 * Warranty rejections recommended by field agents, waiting on a decision.
 *
 * @var list<array<string,mixed>> $rows
 * @var string|null               $status
 * @var array<string,string>      $reasons
 * @var array<string,int>         $counts
 */
$tone = [
    'RECOMMENDED'           => 'warning',
    'UNDER_REVIEW'          => 'info',
    'INFORMATION_REQUESTED' => 'info',
    'UPHELD'                => 'danger',
    'DISMISSED'             => 'success',
    'WITHDRAWN'             => 'secondary',
];
?>
<div class="page-head">
    <div>
        <h1>Warranty reviews</h1>
        <p>A field agent can recommend that a warranty be rejected; only this screen decides it.
            Until it does, the customer's cover stands.</p>
    </div>
</div>

<div class="row g-3 mb-3">
    <?php foreach (['RECOMMENDED' => 'Recommended', 'UNDER_REVIEW' => 'Under review', 'INFORMATION_REQUESTED' => 'Waiting on dealer', 'UPHELD' => 'Upheld'] as $key => $label): ?>
        <div class="col-6 col-lg-3">
            <a class="stat d-block text-decoration-none" href="<?= site_url('admin/warranty-reviews?status=' . $key) ?>">
                <div class="stat__label"><?= esc($label) ?></div>
                <div class="stat__value"><?= (int) ($counts[$key] ?? 0) ?></div>
            </a>
        </div>
    <?php endforeach ?>
</div>

<p class="small">
    <?= $status !== null
        ? '<a href="' . site_url('admin/warranty-reviews') . '">Show everything still open</a>'
        : 'Showing everything still open. ' ?>
    <?php if ($status === null): ?>
        <?php foreach (['UPHELD' => 'upheld', 'DISMISSED' => 'dismissed', 'WITHDRAWN' => 'withdrawn'] as $key => $label): ?>
            <a class="me-2" href="<?= site_url('admin/warranty-reviews?status=' . $key) ?>"><?= esc($label) ?></a>
        <?php endforeach ?>
    <?php endif ?>
</p>

<div class="panel">
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Reference</th><th>Mattress</th><th>Dealer</th><th>Reason</th><th>Raised by</th><th>Warranty</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><a class="mono fw-semibold" href="<?= site_url('admin/warranty-reviews/' . $row['id']) ?>"><?= esc($row['reference']) ?></a>
                    <span class="d-block small text-muted"><?= esc(local_date($row['raised_at'])) ?></span></td>
                <td><a class="mono" href="<?= site_url('admin/mattresses/' . $row['mattress_id']) ?>"><?= esc($row['serial_number']) ?></a>
                    <span class="d-block small text-muted"><?= esc($row['product_name']) ?> · <?= esc($row['size_label']) ?></span></td>
                <td class="small"><?= esc($row['dealer']) ?><span class="d-block text-muted"><?= esc($row['dealer_city'] ?? '') ?></span></td>
                <td class="small"><?= esc($reasons[$row['reason']] ?? $row['reason']) ?></td>
                <td class="small"><?= esc($row['raised_by_name'] ?? '—') ?></td>
                <td class="small">
                    <?= $row['warranty_status'] !== null ? esc(humanise($row['warranty_status'])) : 'none on record' ?>
                    <?php if ($row['invoice_number'] === null): ?>
                        <span class="d-block text-warning">no sale recorded</span>
                    <?php endif ?>
                </td>
                <td><span class="badge text-bg-<?= $tone[$row['status']] ?? 'secondary' ?>"><?= esc(humanise($row['status'])) ?></span></td>
            </tr>
        <?php endforeach ?>
        <?php if ($rows === []): ?><tr><td colspan="7" class="table-empty">Nothing waiting.</td></tr><?php endif ?>
        </tbody>
    </table></div>
</div>
<?= $this->endSection() ?>
