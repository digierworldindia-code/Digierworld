<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Request inspection', 'breadcrumbs' => ['Inspection' => $area . '/inspections', 'New request' => null]]) ?>
<div class="row g-4">
    <div class="col-lg-8">
        <form method="post" action="<?= site_url($area . '/inspections') ?>" novalidate>
            <?= csrf_field() ?>
            <?php if ($productId): ?><input type="hidden" name="product_id" value="<?= (int) $productId ?>"><?php endif ?>
            <fieldset class="bc-fieldset"><legend>Service</legend>
                <div class="row g-3">
                    <div class="col-md-6"><?= select_field('inspection_type', 'Inspection type', ['in_house' => 'In-house (BearingCave inspector)', 'third_party' => 'Third-party agency'], 'in_house', ['required' => true, 'class' => '', 'attrs' => 'id="inspType"']) ?></div>
                    <div class="col-md-6"><?= select_field('order_id', 'Related order (optional)', array_column(array_map(static fn ($o) => ['id' => $o['id'], 'label' => $o['order_number'] . ' · ' . money($o['subtotal'], $o['currency'])], $orders), 'label', 'id'), $orderId, ['class' => '', 'placeholder' => '— Not linked to an order —', 'help' => 'Linking an order uses its invoice value and shares the report with the counterparty.']) ?></div>
                </div>
                <span class="form-label d-block mt-2">Services required<span class="required-mark" aria-hidden="true">*</span></span>
                <div class="row">
                    <?php foreach ($scopes as $k => $l): ?><div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="scope[]" value="<?= $k ?>" id="sc<?= $k ?>"<?= in_array($k, (array) old('scope', ['physical', 'quantity']), true) ? ' checked' : '' ?>><label class="form-check-label" for="sc<?= $k ?>"><?= esc($l) ?></label></div></div><?php endforeach ?>
                </div>
            </fieldset>
            <fieldset class="bc-fieldset"><legend>Location &amp; value</legend>
                <div class="row g-3">
                    <div class="col-md-6"><?= select_field('location_country', 'Goods location (country)', country_options(), $company['country_code'], ['required' => true, 'class' => '']) ?></div>
                    <div class="col-md-6"><?= field('location_city', 'City', null, ['class' => '']) ?></div>
                    <div class="col-md-4"><?= field('invoice_value', 'Total invoice value', null, ['class' => '', 'attrs' => 'inputmode="decimal" id="invValue"', 'help' => 'Ignored when an order is selected.']) ?></div>
                    <div class="col-md-4"><?= select_field('currency', 'Currency', currency_options(), 'INR', ['class' => '']) ?></div>
                    <div class="col-md-4"><?= field('shipment_volume_cbm', 'Shipment volume (m³)', null, ['class' => '', 'attrs' => 'inputmode="decimal"']) ?></div>
                    <div class="col-12"><?= textarea_field('notes', 'Notes for the inspector', null, ['rows' => 3, 'class' => '']) ?></div>
                </div>
            </fieldset>
            <button class="btn btn-primary" type="submit">Submit request</button> <a class="btn btn-light" href="<?= site_url($area . '/inspections') ?>">Cancel</a>
        </form>
    </div>
    <div class="col-lg-4">
        <div class="bc-card" data-fee-estimate data-pct="<?= esc((string) $pct, 'attr') ?>" data-min="<?= esc((string) $min, 'attr') ?>" data-value-input="#invValue" data-type-input="#inspType"><div class="bc-card-body">
            <h2 class="h6">Fee estimate</h2>
            <p class="fs-5 fw-bold text-navy mb-1" data-fee-output aria-live="polite">—</p>
            <p class="small text-muted mb-0">In-house: <?= esc(rtrim(rtrim(number_format($pct, 2), '0'), '.')) ?>% of invoice value<?= $min > 0 ? ', minimum ' . money($min) : '' ?>. Third-party: quoted by volume and location. You'll receive a formal quotation to accept before any work starts.</p>
        </div></div>
    </div>
</div>
<?= $this->endSection() ?>
