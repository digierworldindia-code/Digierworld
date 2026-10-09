<?= $this->extend('layouts/public') ?>
<?= $this->section('content') ?>
<?php $pct = (float) policy('inspection.in_house_rate_pct', 10); $min = (float) policy('inspection.in_house_min_fee', 0); ?>
<?= view('web/pages/_hero', ['eyebrow' => 'Services', 'heading' => 'Inspection services', 'lead' => 'Reduce risk on surplus purchases with an independent check before goods are dispatched.']) ?>
<div class="container py-5">
    <div class="row g-4">
        <div class="col-lg-7">
            <h2 class="h5">What can be inspected</h2>
            <ul class="small"><li>Physical inspection of goods and condition</li><li>Quantity verification against the order</li><li>Packaging inspection</li><li>Batch / lot authenticity checks</li><li>Review of relevant test certificates</li></ul>
            <h2 class="h5 mt-4">How it works</h2>
            <ol class="small"><li>Request inspection from a product, order or your dashboard.</li><li>BearingCave sends a quotation.</li><li>Accept the quotation (an invoice is issued).</li><li>An inspector is assigned and the inspection is scheduled.</li><li>The inspection report and evidence are uploaded to your order.</li></ol>
            <p class="small text-muted">BearingCave only records an inspection as completed after a report has been uploaded. Product quality and warranty obligations remain with the supplier and buyer under their commercial terms.</p>
        </div>
        <div class="col-lg-5">
            <div class="bc-card p-4 mb-3"><h3 class="h6">In-house inspection</h3><p class="fs-4 fw-bold text-navy mb-1"><?= esc(rtrim(rtrim(number_format($pct, 2), '0'), '.')) ?>% <span class="fs-6 fw-normal text-muted">of total invoice value</span></p><p class="small text-muted mb-0"><?= $min > 0 ? 'Minimum charge ' . money($min) . '. ' : '' ?>Final fee confirmed in the quotation. <?= service('settings_store')->isApproved('inspection.in_house_min_fee') ? '' : pending_badge('Minimum charge pending approval') ?></p></div>
            <div class="bc-card p-4"><h3 class="h6">Third-party inspection</h3><p class="small text-muted mb-0">Priced by shipment volume and location. BearingCave obtains and shares a quotation before any work begins.</p></div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
