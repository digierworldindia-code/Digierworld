<?= $this->extend('layouts/floor') ?>

<?php
/**
 * The confirmation screen: everything on the consignment, in one view, with
 * the lorry details and the one button that sends it.
 *
 * Sending is the moment custody passes to the dealer, so it is deliberately
 * a separate screen from picking, and it asks for confirmation.
 *
 * @var array<string,mixed>                              $d
 * @var list<array<string,mixed>>                        $items
 * @var array<string,mixed>                              $counter
 * @var list<array{product:string, size:string, n:int}>  $mix
 */
?>

<?= $this->section('content') ?>

<div class="page-head">
    <h1>Confirm dispatch</h1>
    <p><span class="mono"><?= esc($d['dispatch_code']) ?></span> · <?= count($items) ?> mattresses</p>
</div>

<div class="panel mb-3">
    <div class="p-3">
        <dl class="row mb-0">
            <dt class="col-5">Dealer</dt>
            <dd class="col-7"><?= esc($d['dealer']) ?><?= $d['dealer_city'] !== null ? '<span class="d-block small text-muted">' . esc($d['dealer_city']) . '</span>' : '' ?></dd>
            <dt class="col-5">From</dt><dd class="col-7"><?= esc($d['warehouse']) ?></dd>
            <dt class="col-5">Total</dt><dd class="col-7 fw-semibold"><?= count($items) ?> mattresses</dd>
        </dl>
    </div>
</div>

<h2 class="h2 mb-2">What is on it</h2>
<div class="panel mb-3">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Model</th><th>Size</th><th class="text-end">Qty</th></tr></thead>
            <tbody>
            <?php foreach ($mix as $row): ?>
                <tr><td><?= esc($row['product']) ?></td><td class="small"><?= esc($row['size']) ?></td>
                    <td class="text-end fw-semibold"><?= $row['n'] ?></td></tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>

<details class="panel p-3 mb-3">
    <summary>All <?= count($items) ?> serial numbers</summary>
    <div class="mt-2 small mono" style="max-height:14rem;overflow-y:auto">
        <?php foreach ($items as $item): ?>
            <div><?= esc($item['serial_number']) ?></div>
        <?php endforeach ?>
    </div>
</details>

<form method="post" action="<?= site_url('warehouse/dispatch/' . $d['id'] . '/send') ?>"
      data-confirm="Send <?= count($items) ?> mattresses to <?= esc($d['dealer'], 'attr') ?>? This hands them over.">
    <?= csrf_field() ?>

    <div class="panel p-3 mb-3">
        <h2 class="h3 mb-3">Lorry details <span class="small text-muted fw-normal">(optional)</span></h2>
        <div class="row g-2">
            <div class="col-12 col-sm-6">
                <label class="form-label" for="transporter">Transporter</label>
                <input class="form-control" type="text" id="transporter" name="transporter" maxlength="120" autocomplete="off">
            </div>
            <div class="col-6 col-sm-6">
                <label class="form-label" for="vehicle_number">Vehicle</label>
                <input class="form-control" type="text" id="vehicle_number" name="vehicle_number" maxlength="30" autocomplete="off">
            </div>
            <div class="col-6 col-sm-6">
                <label class="form-label" for="lr_number">LR number</label>
                <input class="form-control" type="text" id="lr_number" name="lr_number" maxlength="60" autocomplete="off">
            </div>
            <div class="col-6 col-sm-6">
                <label class="form-label" for="invoice_number">Invoice</label>
                <input class="form-control" type="text" id="invoice_number" name="invoice_number" maxlength="60" autocomplete="off">
            </div>
        </div>
    </div>

    <div class="floor-commit">
        <button class="btn btn-primary w-100" type="submit">
            <i class="bi bi-truck me-2" aria-hidden="true"></i>Confirm dispatch
        </button>
        <a class="btn btn-light btn-floor w-100 mt-2" href="<?= site_url('warehouse/dispatch/' . $d['id']) ?>">
            Back to picking
        </a>
    </div>
</form>

<?php if ($ctx->can('dispatch:cancel')): ?>
    <details class="panel p-3 mt-3">
        <summary>Cancel this consignment</summary>
        <form class="mt-3" method="post" action="<?= site_url('warehouse/dispatch/' . $d['id'] . '/cancel') ?>"
              data-confirm="Cancel <?= esc($d['dispatch_code'], 'attr') ?> and return every mattress to stock?">
            <?= csrf_field() ?>
            <label class="form-label" for="reason">Why?</label>
            <textarea class="form-control mb-2" id="reason" name="reason" rows="2" maxlength="500" required></textarea>
            <button class="btn btn-light btn-floor w-100" type="submit">Cancel and release the stock</button>
        </form>
    </details>
<?php endif ?>

<?= $this->endSection() ?>
