<?= $this->extend('layouts/floor') ?>

<?php
/**
 * What has gone out, newest first.
 *
 * The status says where each consignment has got to: sent and not yet
 * acknowledged, partly received, or fully received by the dealer.
 *
 * @var list<array<string,mixed>> $rows
 */
?>

<?= $this->section('content') ?>

<div class="page-head">
    <h1>Dispatch history</h1>
    <p>Consignments that have left the plant.</p>
</div>

<?php if ($rows === []): ?>
    <div class="panel p-4 text-center text-muted">Nothing has been dispatched yet.</div>
<?php else: ?>
    <div class="panel">
        <ul class="list-unstyled mb-0">
            <?php foreach ($rows as $row): ?>
                <li class="border-bottom">
                    <a class="d-block p-3 text-decoration-none text-body" href="<?= site_url('warehouse/dispatch/' . $row['id']) ?>">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <strong><?= esc($row['dealer']) ?></strong>
                                <span class="d-block small text-muted mono"><?= esc($row['dispatch_code']) ?></span>
                            </div>
                            <?= pill($row['status']) ?>
                        </div>
                        <div class="small text-muted mt-1">
                            <?= (int) $row['items'] ?> mattresses · <?= esc(local_date($row['dispatched_at'])) ?>
                            <?= $row['transporter'] !== null ? ' · ' . esc($row['transporter']) : '' ?>
                        </div>
                    </a>
                </li>
            <?php endforeach ?>
        </ul>
    </div>
<?php endif ?>

<?= $this->endSection() ?>
