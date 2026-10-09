<?= $this->extend('layouts/public') ?>
<?= $this->section('content') ?>
<?= view('web/pages/_hero', ['eyebrow' => 'How it works', 'heading' => 'From surplus stock to a secure B2B transaction', 'lead' => 'BearingCave supports both inventory liquidation and professional procurement. Every step is recorded, and confidential information is shared only with the parties who need it.']) ?>
<div class="container py-5">
    <div class="row g-4">
        <?php foreach ([
            ['bi-person-plus', 'Register', 'Buyers and suppliers register free. Suppliers stay anonymous to buyers by default.'],
            ['bi-patch-check', 'Verify', 'Companies submit KYC/KYB documents; suppliers go through compliance, financial, risk, quality, site-audit and sanctions checks.'],
            ['bi-boxes', 'List inventory', 'Suppliers list lots (and, when verified, per-piece stock) with part numbers, specifications, inventory age and country visibility.'],
            ['bi-search', 'Search & compare', 'Buyers search by part number, OEM number, cross reference, brand, dimensions or vehicle and compare suppliers.'],
            ['bi-megaphone', 'RFQ & bidding', 'Buyers send RFQs. BearingCave reviews them and distributes them to matched verified suppliers, who submit confidential quotations.'],
            ['bi-check2-square', 'Agree terms', 'Buyers compare offers, negotiate through the platform and accept an offer. Orders are created only from agreed commercial terms.'],
            ['bi-clipboard-check', 'Inspect (optional)', 'In-house inspection or third-party inspection before dispatch, with evidence and reports stored on the order.'],
            ['bi-truck', 'Ship & settle', 'Supplier-direct, BearingCave-managed, confidential or consolidated shipping, with tracking and payment records.'],
        ] as $i => [$icon, $h, $t]): ?>
        <div class="col-md-6 col-lg-3"><div class="bc-card h-100 p-4"><div class="d-flex align-items-center gap-2 mb-2"><span class="step-num"><?= $i + 1 ?></span><i class="bi <?= $icon ?> fs-4 text-primary" aria-hidden="true"></i></div><h2 class="h6"><?= esc($h) ?></h2><p class="small text-muted mb-0"><?= esc($t) ?></p></div></div>
        <?php endforeach ?>
    </div>
    <div class="alert alert-light border mt-5 small"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Escrow-supported payments will be offered through a licensed payment/escrow partner once selected. BearingCave does not hold funds itself.</div>
</div>
<?= $this->endSection() ?>
