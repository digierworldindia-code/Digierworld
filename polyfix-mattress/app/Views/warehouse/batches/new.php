<?= $this->extend('layouts/floor') ?>

<?php
/**
 * Open a batch: the date, the plant, and how many of each size.
 *
 * There is no "planned quantity" field. It is the sum of the sizes, shown as
 * the operator types, because two numbers that have to agree are two numbers
 * that will not.
 *
 * Sizes are grouped by model, and a row is only part of the batch once it has
 * a number in it, so a plant that makes two sizes does not scroll past forty.
 *
 * @var list<array<string,mixed>> $warehouses
 * @var list<array<string,mixed>> $variants
 */
$byProduct = [];
foreach ($variants as $variant) {
    $byProduct[$variant['product']][] = $variant;
}
?>

<?= $this->section('content') ?>

<div class="page-head">
    <h1>New batch</h1>
    <p>Serials and QR labels are issued as soon as you open it.</p>
</div>

<?php if ($variants === []): ?>
    <div class="panel p-4">
        <p class="mb-0">No sizes are set up yet. The office has to add a model and its sizes in the console
            before a batch can be opened.</p>
    </div>
<?php else: ?>
<form method="post" action="<?= site_url('warehouse/batches') ?>" data-sum-form>
    <?= csrf_field() ?>

    <div class="panel p-3 mb-3">
        <div class="mb-3">
            <label class="form-label fw-semibold" for="manufactured_on">Date made</label>
            <input class="form-control form-control-lg" type="date" id="manufactured_on" name="manufactured_on"
                   value="<?= esc(old('manufactured_on', local_time(utc_now(), 'Y-m-d')), 'attr') ?>"
                   max="<?= esc(local_time(utc_now(), 'Y-m-d'), 'attr') ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold" for="warehouse_id">Plant</label>
            <select class="form-select form-select-lg" id="warehouse_id" name="warehouse_id" required>
                <?php foreach ($warehouses as $warehouse): ?>
                    <option value="<?= esc($warehouse['id'], 'attr') ?>"<?= old('warehouse_id') === $warehouse['id'] ? ' selected' : '' ?>>
                        <?= esc($warehouse['name']) ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>

        <div class="mb-0">
            <label class="form-label fw-semibold" for="line_supervisor">Line supervisor <span class="text-muted fw-normal">(optional)</span></label>
            <input class="form-control" type="text" id="line_supervisor" name="line_supervisor"
                   value="<?= esc(old('line_supervisor'), 'attr') ?>" maxlength="120" autocomplete="off">
        </div>
    </div>

    <h2 class="h2 mb-2">How many of each</h2>

    <?php foreach ($byProduct as $product => $sizes): ?>
        <div class="panel mb-3">
            <div class="panel-head"><strong><?= esc($product) ?></strong></div>
            <div class="p-2">
                <?php foreach ($sizes as $size): ?>
                    <div class="d-flex align-items-center gap-2 py-1">
                        <label class="flex-grow-1 mb-0" for="qty-<?= esc($size['id'], 'attr') ?>">
                            <?= esc($size['size_label']) ?>
                            <span class="d-block small text-muted mono"><?= esc($size['sku']) ?></span>
                        </label>
                        <input class="form-control text-center" style="max-width:6.5rem"
                               type="number" inputmode="numeric" min="0" max="2000" step="1"
                               id="qty-<?= esc($size['id'], 'attr') ?>"
                               name="qty[<?= esc($size['id'], 'attr') ?>]"
                               value="<?= esc(old('qty.' . $size['id']), 'attr') ?>"
                               placeholder="0" data-sum-input>
                    </div>
                <?php endforeach ?>
            </div>
        </div>
    <?php endforeach ?>

    <div class="floor-commit">
        <button class="btn btn-primary w-100" type="submit">
            Open batch (<span data-sum-total>0</span> mattresses)
        </button>
    </div>
</form>
<?php endif ?>

<?= $this->endSection() ?>
