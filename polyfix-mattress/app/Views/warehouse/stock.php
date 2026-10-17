<?= $this->extend('layouts/floor') ?>

<?php
/**
 * VIEW STOCK: what is on the floor and can go out, by model and size.
 *
 * Only free stock is counted. A unit already staged on a dispatch, gone to a
 * dealer or sold is not stock here — showing it would have the floor
 * promising mattresses it cannot send.
 *
 * @var list<array<string,mixed>> $rows
 * @var int                       $total
 * @var string|null               $plant
 * @var string|null               $q
 * @var list<array<string,mixed>> $warehouses
 */
?>

<?= $this->section('content') ?>

<div class="page-head">
    <h1>Stock</h1>
    <p>Ready to dispatch. Reserved, delivered and sold units are not counted.</p>
</div>

<form class="panel p-3 mb-3" method="get" action="<?= site_url('warehouse/stock') ?>">
    <div class="row g-2">
        <div class="col-12 col-sm-6">
            <label class="form-label" for="plant">Plant</label>
            <select class="form-select" id="plant" name="plant" data-autosubmit>
                <option value="">All plants</option>
                <?php foreach ($warehouses as $warehouse): ?>
                    <option value="<?= esc($warehouse['id'], 'attr') ?>"<?= $plant === $warehouse['id'] ? ' selected' : '' ?>>
                        <?= esc($warehouse['name']) ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="col-12 col-sm-6">
            <label class="form-label" for="q">Model or size</label>
            <div class="input-group">
                <input class="form-control" type="search" id="q" name="q" value="<?= esc($q, 'attr') ?>" maxlength="60" placeholder="e.g. 72x36">
                <button class="btn btn-light" type="submit"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Search</span></button>
            </div>
        </div>
    </div>
</form>

<div class="scanner__counter mb-3"><?= $total ?> mattresses ready</div>

<?php if ($rows === []): ?>
    <div class="panel p-4 text-center text-muted">
        Nothing matches. <?= $q !== null || $plant !== null ? '<a href="' . site_url('warehouse/stock') . '">Clear the filters</a>' : 'Open a batch and scan mattresses in.' ?>
    </div>
<?php else: ?>
    <div class="panel">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr><th>Model</th><th>Size</th><th>Plant</th><th class="text-end">Ready</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= esc($row['product_name']) ?></td>
                        <td class="small"><?= esc($row['size_label']) ?>
                            <span class="d-block text-muted mono small"><?= esc($row['sku']) ?></span></td>
                        <td class="small"><?= esc($row['warehouse'] ?? '—') ?></td>
                        <td class="text-end fw-semibold"><?= (int) $row['available'] ?>
                            <?php if ((int) $row['returned'] > 0): ?>
                                <!-- Returned units are dispatchable again, but it is worth
                                     knowing they are not fresh off the line. -->
                                <span class="d-block small fw-normal text-muted"><?= (int) $row['returned'] ?> returned</span>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif ?>

<?= $this->endSection() ?>
