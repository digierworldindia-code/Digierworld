<?= $this->extend('layouts/floor') ?>

<?php
/**
 * The warehouse panel's front screen.
 *
 * Five buttons, each with the number behind it, so the operator can see
 * where the work is before touching anything. Nothing else: this screen is
 * read at arm's length while holding something.
 *
 * @var array<string,int>        $figures
 * @var list<array<string,mixed>> $batches
 */
?>

<?= $this->section('content') ?>

<div class="page-head">
    <h1>Warehouse</h1>
    <p>Scan, stock and dispatch.</p>
</div>

<div class="floor-menu">
    <a class="floor-action" href="<?= site_url('warehouse/batches') ?>">
        <i class="bi bi-box2" aria-hidden="true"></i>
        <span><strong>Batches</strong><span>Open a batch and scan mattresses in</span></span>
        <span class="count" aria-label="<?= (int) $figures['open_batches'] ?> open"><?= (int) $figures['open_batches'] ?></span>
    </a>

    <a class="floor-action" href="<?= site_url('warehouse/batches/new') ?>">
        <i class="bi bi-plus-square" aria-hidden="true"></i>
        <span><strong>New batch</strong><span>Issue serials and QR labels</span></span>
    </a>

    <a class="floor-action" href="<?= site_url('warehouse/stock') ?>">
        <i class="bi bi-boxes" aria-hidden="true"></i>
        <span><strong>Stock</strong><span>Ready to dispatch, by model and size</span></span>
        <span class="count"><?= (int) $figures['free_stock'] ?></span>
    </a>

    <a class="floor-action" href="<?= site_url('warehouse/dispatch') ?>">
        <i class="bi bi-truck" aria-hidden="true"></i>
        <span><strong>Prepare dispatch</strong><span>Pick mattresses for a dealer</span></span>
        <span class="count"><?= (int) $figures['open_dispatches'] ?></span>
    </a>

    <a class="floor-action floor-action--muted" href="<?= site_url('warehouse/dispatches') ?>">
        <i class="bi bi-clock-history" aria-hidden="true"></i>
        <span><strong>Dispatch history</strong><span>What has gone out, and to whom</span></span>
        <span class="count"><?= (int) $figures['in_transit'] ?></span>
    </a>

    <a class="floor-action floor-action--muted" href="<?= site_url('warehouse/find') ?>">
        <i class="bi bi-search" aria-hidden="true"></i>
        <span><strong>Find a mattress</strong><span>Scan or type a serial</span></span>
    </a>
</div>

<?php if ($batches !== []): ?>
<h2 class="h2 mt-4 mb-2">Recent batches</h2>
<div class="panel">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Batch</th><th>Made</th><th class="text-end">Scanned</th></tr></thead>
            <tbody>
            <?php foreach ($batches as $batch): ?>
                <?php $done = (int) $batch['scanned'] === (int) $batch['produced'] && (int) $batch['produced'] > 0 ?>
                <tr>
                    <td><a class="mono fw-semibold" href="<?= site_url('warehouse/batches/' . $batch['id']) ?>"><?= esc($batch['batch_code']) ?></a></td>
                    <td class="small"><?= esc(local_date($batch['manufactured_on'])) ?></td>
                    <td class="text-end">
                        <span class="badge text-bg-<?= $done ? 'success' : 'secondary' ?>">
                            <?= (int) $batch['scanned'] ?> / <?= (int) $batch['produced'] ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif ?>

<?= $this->endSection() ?>
