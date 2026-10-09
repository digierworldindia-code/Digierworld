<?= $this->extend('layouts/public') ?>
<?= $this->section('content') ?>
<?= view('web/pages/_hero', ['eyebrow' => 'Services', 'heading' => 'Logistics services', 'lead' => 'Choose the shipping model that fits the transaction — including confidential shipping that keeps the supplier anonymous.']) ?>
<div class="container py-5">
    <div class="row g-4">
        <?php foreach ([
            ['bi-truck', 'Supplier-direct', 'The supplier ships directly to you (verified suppliers).'],
            ['bi-building', 'BearingCave-managed', 'BearingCave arranges pickup, documentation and delivery for an additional charge.'],
            ['bi-incognito', 'Confidential shipping', 'The supplier\'s identity and pickup address are not disclosed to the buyer.'],
            ['bi-collection', 'Multi-supplier consolidation', 'Combine purchases from several suppliers into one shipment (verified buyers).'],
        ] as [$i, $h, $t]): ?>
        <div class="col-md-6 col-lg-3"><div class="bc-card h-100 p-4"><span class="feature-icon mb-3"><i class="bi <?= $i ?>" aria-hidden="true"></i></span><h2 class="h6"><?= esc($h) ?></h2><p class="small text-muted mb-0"><?= esc($t) ?></p></div></div>
        <?php endforeach ?>
    </div>
    <div class="bc-card p-4 mt-4"><h2 class="h6">Pricing</h2><p class="small text-muted mb-0">BearingCave-managed logistics may include an optional additional platform charge of <?= esc(rtrim(rtrim(number_format((float) policy('logistics.platform_fee_pct', 10), 2), '0'), '.')) ?>%. Every shipment is quoted before booking. <?= service('settings_store')->isApproved('logistics.platform_fee_basis') ? '' : pending_badge('Calculation base pending approval') ?></p></div>
</div>
<?= $this->endSection() ?>
