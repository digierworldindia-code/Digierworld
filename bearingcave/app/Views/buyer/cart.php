<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Cart / RFQ basket', 'subtitle' => 'Order listed items at their marketplace price, or turn the basket into an RFQ to negotiate.']) ?>
<?php if (! $groups): ?>
    <div class="bc-card"><?= empty_state('bi-cart3', 'Your basket is empty', 'Add listings from product pages.', '<a class="btn btn-primary btn-sm" href="' . site_url('marketplace') . '">Browse marketplace</a>') ?></div>
<?php else: ?>
<div class="row g-4">
    <div class="col-xl-8">
        <?php $grand = []; foreach ($groups as $sid => $lines): $sup = $lines[0]['supplier']; ?>
        <div class="bc-card mb-3">
            <div class="bc-card-header"><h2 class="h6 mb-0"><?= esc(service('visibility')->supplierLabel($sup, $lines[0]['product'])) ?></h2><?= verified_badge($sup) ?: '<span class="badge-soft neutral">Unverified supplier</span>' ?></div>
            <div class="table-responsive"><table class="table table-bc table-stack mb-0">
                <thead><tr><th>Item</th><th>Quantity</th><th class="text-end">Unit</th><th class="text-end">Line total</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($lines as $l): $p = $l['product']; $step = $p['sale_mode'] === 'lot' && (int) $p['lot_quantity'] > 0 ? (int) $p['lot_quantity'] : 1;
                    if ($l['price']['total'] !== null) { $grand[$p['currency']] = ($grand[$p['currency']] ?? 0) + $l['price']['total']; } ?>
                    <tr>
                        <td data-label="Item"><a href="<?= site_url('product/' . $p['slug']) ?>" class="part-no fw-semibold"><?= esc($p['part_number']) ?></a><div class="small"><?= esc($p['name']) ?></div><div class="small text-muted"><?= number_format($l['available']) ?> available · MOQ <?= (int) $p['moq'] ?><?= $step > 1 ? ' · lots of ' . $step : '' ?></div>
                            <?php if ((int) $l['item']['quantity'] > $l['available']): ?><div class="small text-danger">Only <?= number_format($l['available']) ?> available now</div><?php endif ?></td>
                        <td data-label="Quantity"><form method="post" action="<?= site_url('buyer/cart/items/' . $l['item']['id']) ?>" class="d-flex gap-1" style="max-width:170px"><?= csrf_field() ?><label class="visually-hidden" for="q<?= $l['item']['id'] ?>">Quantity</label><input id="q<?= $l['item']['id'] ?>" class="form-control form-control-sm" type="number" name="quantity" min="0" step="<?= $step ?>" value="<?= (int) $l['item']['quantity'] ?>"><button class="btn btn-sm btn-light" type="submit">Update</button></form></td>
                        <td data-label="Unit" class="text-end"><?= $l['price']['unit'] !== null ? money($l['price']['unit'], $p['currency'], 4) : '<span class="text-muted">On request</span>' ?></td>
                        <td data-label="Line total" class="text-end fw-semibold"><?= $l['price']['total'] !== null ? money($l['price']['total'], $p['currency']) : '—' ?></td>
                        <td><?= post_button('buyer/cart/items/' . $l['item']['id'] . '/remove', 'Remove', 'btn btn-sm btn-link text-danger p-0') ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
        </div>
        <?php endforeach ?>
    </div>
    <div class="col-xl-4">
        <div class="bc-card"><div class="bc-card-body">
            <h2 class="h6">Summary</h2>
            <?php foreach ($grand as $cur => $sum): ?><div class="d-flex justify-content-between"><span>Subtotal (<?= esc($cur) ?>)</span><strong><?= money($sum, $cur) ?></strong></div><?php endforeach ?>
            <p class="small text-muted mt-2">Excludes taxes, inspection and logistics. One order is created per supplier and each supplier must confirm.</p>
            <a class="btn btn-primary w-100 mb-2" href="<?= site_url('buyer/cart/checkout') ?>">Proceed to order</a>
            <form method="post" action="<?= site_url('buyer/cart/rfq') ?>"><?= csrf_field() ?><button class="btn btn-outline-primary w-100" type="submit">Request quotation instead</button></form>
        </div></div>
    </div>
</div>
<?php endif ?>
<?= $this->endSection() ?>
