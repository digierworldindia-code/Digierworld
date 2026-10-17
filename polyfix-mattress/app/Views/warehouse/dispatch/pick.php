<?= $this->extend('layouts/floor') ?>

<?php
/**
 * The picking screen: all three ways of putting a mattress on a lorry.
 *
 *   scan     the camera, open and continuous
 *   batch    choose a batch, then tick all, tick a size, or tick singly
 *   on board what is already loaded, with a tick box to take any back off
 *
 * No box asks for a typed serial as part of the job — the scanner's fallback
 * is there for a torn label and nothing else.
 *
 * @var array<string,mixed>       $d
 * @var list<array<string,mixed>> $items
 * @var array<string,mixed>       $counter
 * @var list<array<string,mixed>> $batches
 * @var list<array<string,mixed>> $pickable
 * @var string|null               $batchId
 * @var string|null               $variant
 */
$sizes = [];
foreach ($pickable as $unit) {
    $sizes[$unit['product_variant_id']] = $unit['product_name'] . ' · ' . $unit['size_label'];
}
?>

<?= $this->section('content') ?>

<div class="page-head">
    <h1><?= esc($d['dealer']) ?></h1>
    <p><span class="mono"><?= esc($d['dispatch_code']) ?></span> · from <?= esc($d['warehouse']) ?>
        <?= $d['dealer_city'] !== null ? ' · ' . esc($d['dealer_city']) : '' ?></p>
</div>

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="tab-scan" data-bs-toggle="tab" data-bs-target="#pane-scan"
                type="button" role="tab" aria-controls="pane-scan" aria-selected="true">
            <i class="bi bi-qr-code-scan me-1" aria-hidden="true"></i>Scan
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-batch" data-bs-toggle="tab" data-bs-target="#pane-batch"
                type="button" role="tab" aria-controls="pane-batch" aria-selected="false">
            <i class="bi bi-list-check me-1" aria-hidden="true"></i>From batch
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-loaded" data-bs-toggle="tab" data-bs-target="#pane-loaded"
                type="button" role="tab" aria-controls="pane-loaded" aria-selected="false">
            <!-- Kept in step with the scanner: after twenty scans a number
                 frozen at page load is worse than no number. -->
            On board (<span data-scan-total><?= count($items) ?></span>)
        </button>
    </li>
</ul>

<div class="tab-content">
    <!-- ------------------------------------------------- scanning -->
    <div class="tab-pane fade show active" id="pane-scan" role="tabpanel" aria-labelledby="tab-scan">
        <?= view('partials/scanner', [
            'endpoint' => site_url('warehouse/dispatch/' . $d['id'] . '/scan'),
            'counter'  => $counter['label'],
            'hint'     => 'Scan each mattress as it goes on the lorry.',
        ]) ?>
    </div>

    <!-- ------------------------------------------------- by batch -->
    <div class="tab-pane fade" id="pane-batch" role="tabpanel" aria-labelledby="tab-batch">
        <form class="panel p-3 mb-3" method="get" action="<?= site_url('warehouse/dispatch/' . $d['id']) ?>">
            <label class="form-label fw-semibold" for="batch">Batch</label>
            <select class="form-select form-select-lg mb-2" id="batch" name="batch" data-autosubmit>
                <option value="">Choose a batch…</option>
                <?php foreach ($batches as $batch): ?>
                    <option value="<?= esc($batch['id'], 'attr') ?>"<?= $batchId === $batch['id'] ? ' selected' : '' ?>>
                        <?= esc($batch['batch_code']) ?> — <?= (int) $batch['available'] ?> ready
                    </option>
                <?php endforeach ?>
            </select>

            <?php if ($batchId !== null && count($sizes) > 1): ?>
                <label class="form-label fw-semibold" for="size">Only this size</label>
                <select class="form-select" id="size" name="size" data-autosubmit>
                    <option value="">Every size in the batch</option>
                    <?php foreach ($sizes as $variantId => $label): ?>
                        <option value="<?= esc($variantId, 'attr') ?>"<?= $variant === $variantId ? ' selected' : '' ?>>
                            <?= esc($label) ?>
                        </option>
                    <?php endforeach ?>
                </select>
            <?php endif ?>
        </form>

        <?php if ($batchId === null): ?>
            <div class="panel p-4 text-center text-muted">
                <?= $batches === [] ? 'No free stock at this plant.' : 'Choose a batch to see what is in it.' ?>
            </div>
        <?php elseif ($pickable === []): ?>
            <div class="panel p-4 text-center text-muted">Nothing left to pick in that batch.</div>
        <?php else: ?>
            <form method="post" action="<?= site_url('warehouse/dispatch/' . $d['id'] . '/add') ?>">
                <?= csrf_field() ?>
                <div class="panel mb-3">
                    <div class="panel-head">
                        <label class="form-check-label fw-semibold" for="tick-all">
                            <input class="form-check-input me-2" type="checkbox" id="tick-all" data-check-all="units[]">
                            Tick all <?= count($pickable) ?>
                        </label>
                    </div>
                    <div class="table-responsive" style="max-height:24rem;overflow-y:auto">
                        <table class="table table-hover mb-0">
                            <tbody>
                            <?php foreach ($pickable as $unit): ?>
                                <tr>
                                    <td style="width:2.5rem">
                                        <input class="form-check-input" type="checkbox" name="units[]"
                                               value="<?= esc($unit['id'], 'attr') ?>"
                                               id="u-<?= esc($unit['id'], 'attr') ?>"
                                               aria-label="<?= esc($unit['serial_number'], 'attr') ?>">
                                    </td>
                                    <td>
                                        <label class="mb-0 d-block" for="u-<?= esc($unit['id'], 'attr') ?>">
                                            <span class="mono fw-semibold"><?= esc($unit['serial_number']) ?></span>
                                            <span class="d-block small text-muted">
                                                <?= esc($unit['product_name']) ?> · <?= esc($unit['size_label']) ?>
                                                <?= $unit['current_status'] === 'RETURNED' ? ' · returned' : '' ?>
                                            </span>
                                        </label>
                                    </td>
                                </tr>
                            <?php endforeach ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="floor-commit">
                    <button class="btn btn-primary w-100" type="submit">Add the ticked mattresses</button>
                </div>
            </form>
        <?php endif ?>
    </div>

    <!-- ------------------------------------------------- on board -->
    <div class="tab-pane fade" id="pane-loaded" role="tabpanel" aria-labelledby="tab-loaded">
        <?php if ($items === []): ?>
            <div class="panel p-4 text-center text-muted">Nothing on this consignment yet.</div>
        <?php else: ?>
            <form method="post" action="<?= site_url('warehouse/dispatch/' . $d['id'] . '/remove') ?>"
                  data-confirm="Take the ticked mattresses off this consignment?">
                <?= csrf_field() ?>
                <div class="panel mb-3">
                    <div class="table-responsive" style="max-height:24rem;overflow-y:auto">
                        <table class="table mb-0">
                            <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td style="width:2.5rem">
                                        <input class="form-check-input" type="checkbox" name="units[]"
                                               value="<?= esc($item['id'], 'attr') ?>"
                                               id="off-<?= esc($item['id'], 'attr') ?>"
                                               aria-label="Take <?= esc($item['serial_number'], 'attr') ?> off">
                                    </td>
                                    <td>
                                        <label class="mb-0 d-block" for="off-<?= esc($item['id'], 'attr') ?>">
                                            <span class="mono fw-semibold"><?= esc($item['serial_number']) ?></span>
                                            <span class="d-block small text-muted">
                                                <?= esc($item['product_name']) ?> · <?= esc($item['size_label']) ?>
                                                <?= $item['scanned_via'] !== null ? ' · scanned' : ' · ticked' ?>
                                            </span>
                                        </label>
                                    </td>
                                </tr>
                            <?php endforeach ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <button class="btn btn-light btn-floor w-100 mb-3" type="submit">Take the ticked ones off</button>
            </form>
        <?php endif ?>
    </div>
</div>

<div class="floor-commit">
    <?php if ($items === []): ?>
        <button class="btn btn-primary w-100" type="button" disabled>Add a mattress first</button>
    <?php else: ?>
        <a class="btn btn-primary w-100" href="<?= site_url('warehouse/dispatch/' . $d['id'] . '/confirm') ?>">
            Review and send (<span data-scan-total><?= count($items) ?></span>)
        </a>
    <?php endif ?>
</div>

<?= $this->endSection() ?>
