<?= $this->extend('layouts/public') ?>
<?= $this->section('content') ?>
<?= view('web/pages/_hero', ['eyebrow' => 'For suppliers & manufacturers', 'heading' => 'Turn surplus stock into working capital', 'lead' => 'Surplus, obsolete and slow-moving inventory ties up capital, warehouse space and depreciates over time. BearingCave helps you sell it to professional buyers — without disrupting your regular channels.']) ?>
<div class="container py-5">
    <div class="row g-4">
        <?php foreach ([
            ['bi-incognito', 'Confidential by default', 'Your company name and contact details are hidden unless you are verified and choose to publish them. Listings can be identity-confidential even then.'],
            ['bi-globe2', 'Country visibility controls', 'Verified suppliers can allow or exclude countries per listing or company-wide, so discounted stock does not appear in sensitive markets.'],
            ['bi-tags', 'Lot or per-piece pricing', 'Sell entire lots on the free plan; verified suppliers can also sell per piece with quantity-based pricing tiers.'],
            ['bi-megaphone', 'Premium RFQ leads', 'Verified suppliers receive RFQs that match their stock, brands, categories and approved-vendor status, and can submit confidential bids.'],
            ['bi-file-earmark-spreadsheet', 'Bulk Excel/CSV upload', 'Map your own spreadsheet columns, preview validation errors and duplicates, then import thousands of lines.'],
            ['bi-graph-up', 'Performance & demand insights', 'Track enquiries, RFQ response rates, delivery performance and what buyers are searching for in your categories.'],
        ] as [$i, $h, $t]): ?>
        <div class="col-md-6 col-lg-4"><div class="bc-card h-100 p-4"><span class="feature-icon mb-3"><i class="bi <?= $i ?>" aria-hidden="true"></i></span><h2 class="h6"><?= esc($h) ?></h2><p class="small text-muted mb-0"><?= esc($t) ?></p></div></div>
        <?php endforeach ?>
    </div>
    <div class="d-flex flex-wrap gap-2 mt-4"><a class="btn btn-primary" href="<?= site_url('register/supplier') ?>">Register free</a><a class="btn btn-outline-primary" href="<?= site_url('membership') ?>">Compare plans</a></div>
</div>
<?= $this->endSection() ?>
