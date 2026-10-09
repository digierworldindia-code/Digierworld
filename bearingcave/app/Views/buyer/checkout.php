<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Place order', 'breadcrumbs' => ['Cart' => 'buyer/cart', 'Place order' => null]]) ?>
<form method="post" action="<?= site_url('buyer/cart/checkout') ?>" novalidate>
<?= csrf_field() ?>
<div class="row g-4">
    <div class="col-lg-7">
        <fieldset class="bc-fieldset"><legend>Delivery</legend>
            <div class="row g-3">
                <div class="col-md-6"><?= select_field('delivery_country', 'Delivery country', country_options(), $company['country_code'], ['required' => true, 'class' => '']) ?></div>
                <div class="col-md-6"><?= select_field('incoterm', 'Incoterm', ['EXW' => 'EXW', 'FCA' => 'FCA', 'FOB' => 'FOB', 'CIF' => 'CIF', 'DAP' => 'DAP', 'DDP' => 'DDP'], null, ['class' => '', 'placeholder' => 'Agree with supplier']) ?></div>
                <div class="col-12"><?= textarea_field('delivery_address', 'Delivery address', trim(($company['address_line1'] ?? '') . ', ' . ($company['city'] ?? ''), ', '), ['required' => true, 'rows' => 2, 'class' => '']) ?></div>
                <div class="col-md-6"><?= select_field('logistics_mode', 'Shipping', ['platform_managed' => 'BearingCave-managed shipping', 'confidential' => 'Confidential shipping', 'buyer_arranged' => 'I will arrange collection', 'supplier_direct' => 'Supplier ships directly (where offered)'], 'platform_managed', ['required' => true, 'class' => '']) ?></div>
                <div class="col-md-6"><?= field('buyer_po_reference', 'Your PO reference', null, ['class' => '']) ?></div>
            </div>
        </fieldset>
        <div class="alert alert-light border small">
            <p class="mb-1"><strong>How payment works:</strong> after each supplier confirms, a proforma invoice is issued. <?= $escrow ? 'Escrow-supported payment is available.' : 'Escrow-supported payments will be offered once the licensed escrow partner is configured; until then pay by bank transfer, confirmed by BearingCave finance.' ?></p>
            <p class="mb-1"><?= esc((string) $warranty) ?></p>
            <p class="mb-0"><?= esc((string) $refundPolicy) ?></p>
        </div>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="accept_terms" value="1" id="acc" required><label class="form-check-label" for="acc">I confirm the quantities and accept the listed prices, subject to supplier confirmation and BearingCave's order terms.</label></div>
        <button class="btn btn-primary" type="submit">Place order &amp; reserve stock</button> <a class="btn btn-light" href="<?= site_url('buyer/cart') ?>">Back to cart</a>
    </div>
    <div class="col-lg-5">
        <div class="bc-card"><div class="bc-card-header"><h2>Items</h2></div><ul class="list-group list-group-flush small">
        <?php foreach ($lines as $l): ?><li class="list-group-item d-flex justify-content-between gap-2"><span><span class="part-no"><?= esc($l['product']['part_number']) ?></span> × <?= number_format((int) $l['item']['quantity']) ?></span><span><?= $l['price']['total'] !== null ? money($l['price']['total'], $l['product']['currency']) : '<span class="text-danger">Price on request</span>' ?></span></li><?php endforeach ?>
        </ul></div>
    </div>
</div>
</form>
<?= $this->endSection() ?>
