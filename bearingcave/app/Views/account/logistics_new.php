<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Request logistics', 'breadcrumbs' => ['Logistics' => $area . '/logistics', 'New request' => null]]) ?>
<div class="row g-4">
    <div class="col-lg-8">
        <form method="post" action="<?= site_url($area . '/logistics') ?>" novalidate>
            <?= csrf_field() ?>
            <fieldset class="bc-fieldset"><legend>Shipping option</legend>
                <div class="row g-3">
                    <div class="col-md-6"><?= select_field('mode', 'Mode', $modes, 'platform_managed', ['required' => true, 'class' => '']) ?></div>
                    <div class="col-md-6"><?= select_field('order_id', 'Related order', array_column(array_map(static fn ($o) => ['id' => $o['id'], 'label' => $o['order_number']], $orders), 'label', 'id'), $orderId, ['class' => '', 'placeholder' => '— Not linked —']) ?></div>
                    <?php if (isset($modes['consolidation'])): ?><div class="col-md-6"><?= field('consolidation_ref', 'Consolidation group reference', null, ['class' => '', 'help' => 'Use the same reference on requests that should ship together.']) ?></div><?php endif ?>
                    <div class="col-md-6"><?= select_field('incoterm', 'Incoterm', ['EXW' => 'EXW', 'FCA' => 'FCA', 'FOB' => 'FOB', 'CIF' => 'CIF', 'CPT' => 'CPT', 'DAP' => 'DAP', 'DDP' => 'DDP'], null, ['class' => '', 'placeholder' => 'Select']) ?></div>
                </div>
            </fieldset>
            <fieldset class="bc-fieldset"><legend>Pickup</legend>
                <div class="row g-3">
                    <div class="col-md-4"><?= select_field('pickup_country', 'Country', country_options(), $area === 'supplier' ? $company['country_code'] : null, ['class' => '', 'placeholder' => 'Select']) ?></div>
                    <div class="col-md-4"><?= field('pickup_city', 'City', null, ['class' => '']) ?></div>
                    <div class="col-md-4"><?= field('pickup_contact', 'Contact person / phone', null, ['class' => '']) ?></div>
                    <div class="col-12"><?= textarea_field('pickup_address', 'Pickup address', null, ['rows' => 2, 'class' => '', 'help' => 'For confidential and BearingCave-managed shipments, the pickup address is never shown to the buyer.']) ?></div>
                </div>
            </fieldset>
            <fieldset class="bc-fieldset"><legend>Destination</legend>
                <div class="row g-3">
                    <div class="col-md-4"><?= select_field('destination_country', 'Country', country_options(), $area === 'buyer' ? $company['country_code'] : null, ['required' => true, 'class' => '', 'placeholder' => 'Select']) ?></div>
                    <div class="col-md-4"><?= field('destination_city', 'City', null, ['class' => '']) ?></div>
                    <div class="col-md-4"><?= field('destination_contact', 'Contact person / phone', null, ['class' => '']) ?></div>
                    <div class="col-12"><?= textarea_field('destination_address', 'Delivery address', null, ['rows' => 2, 'class' => '']) ?></div>
                </div>
            </fieldset>
            <fieldset class="bc-fieldset"><legend>Cargo</legend>
                <div class="row g-3">
                    <div class="col-md-12"><?= field('cargo_description', 'Description', null, ['class' => '']) ?></div>
                    <div class="col-md-3"><?= field('weight_kg', 'Weight (kg)', null, ['class' => '', 'attrs' => 'inputmode="decimal"']) ?></div>
                    <div class="col-md-3"><?= field('volume_cbm', 'Volume (m³)', null, ['class' => '', 'attrs' => 'inputmode="decimal"']) ?></div>
                    <div class="col-md-2"><?= field('packages', 'Packages', null, ['type' => 'number', 'class' => '']) ?></div>
                    <div class="col-md-2"><?= field('goods_value', 'Goods value', null, ['class' => '', 'attrs' => 'inputmode="decimal"']) ?></div>
                    <div class="col-md-2"><?= select_field('currency', 'Currency', currency_options(), 'INR', ['class' => '']) ?></div>
                    <div class="col-12"><?= textarea_field('notes', 'Notes', null, ['rows' => 2, 'class' => '']) ?></div>
                </div>
            </fieldset>
            <button class="btn btn-primary" type="submit">Request quotation</button> <a class="btn btn-light" href="<?= site_url($area . '/logistics') ?>">Cancel</a>
        </form>
    </div>
    <div class="col-lg-4">
        <div class="bc-card mb-3"><div class="bc-card-body"><h2 class="h6">Pricing</h2><p class="small text-muted mb-0"><?= esc($estimate['basis']) ?></p></div></div>
        <?php if (isset($modes['consolidation'])): ?><div class="bc-card"><div class="bc-card-body"><h2 class="h6">Consolidation</h2><p class="small text-muted mb-0"><?= esc((string) $consolidationPolicy) ?></p></div></div><?php endif ?>
    </div>
</div>
<?= $this->endSection() ?>
