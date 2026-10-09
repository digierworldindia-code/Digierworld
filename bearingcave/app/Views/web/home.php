<?= $this->extend('layouts/public') ?>
<?= $this->section('content') ?>
<section class="hero">
    <div class="container py-5">
        <div class="row align-items-center g-5 py-lg-4">
            <div class="col-lg-7">
                <p class="eyebrow text-white-50 mb-2">Global B2B automotive surplus marketplace</p>
                <h1 class="mb-3">Genuine automotive surplus,<br class="d-none d-md-inline"> traded with confidence.</h1>
                <p class="lead mb-4">BearingCave connects manufacturers holding surplus, obsolete and slow-moving stock with verified buyers worldwide — with confidential listings, RFQs, inspection and managed logistics.</p>
                <form class="hero-search" action="<?= site_url('marketplace') ?>" method="get" role="search">
                    <div class="d-flex flex-column flex-sm-row gap-1 position-relative">
                        <label class="visually-hidden" for="heroQ">Search by part number, OEM number or brand</label>
                        <input id="heroQ" class="form-control form-control-lg" type="search" name="q" placeholder="e.g. 6204-2RS, 30205, OEM number or brand" data-autocomplete="<?= site_url('search/suggest') ?>">
                        <label class="visually-hidden" for="heroCat">Category</label>
                        <select id="heroCat" class="form-select form-select-lg" name="category">
                            <option value="">All categories</option>
                            <?php foreach ($categories as $c): ?><option value="<?= esc($c['slug'], 'attr') ?>"><?= esc($c['name']) ?></option><?php endforeach ?>
                        </select>
                        <button class="btn btn-primary btn-lg px-4" type="submit"><i class="bi bi-search me-1" aria-hidden="true"></i>Search</button>
                    </div>
                </form>
                <div class="d-flex flex-wrap gap-4 mt-4">
                    <div class="hero-stat"><strong><?= number_format((int) $stats['listings']) ?></strong><span>live listings</span></div>
                    <div class="hero-stat"><strong><?= number_format((int) $stats['verified_suppliers']) ?></strong><span>verified suppliers</span></div>
                    <div class="hero-stat"><strong><?= number_format((int) $stats['countries']) ?></strong><span>stock locations (countries)</span></div>
                </div>
            </div>
            <div class="col-lg-5 d-none d-lg-block">
                <div class="bg-white text-body rounded-4 p-4 shadow-lg">
                    <p class="small-caps mb-3">Two ways to trade</p>
                    <div class="d-flex gap-3 mb-3"><span class="feature-icon"><i class="bi bi-search" aria-hidden="true"></i></span><div><div class="fw-semibold text-navy">Buyers</div><div class="small text-muted">Search by part number, compare offers, send RFQs and order with optional inspection.</div></div></div>
                    <div class="d-flex gap-3 mb-4"><span class="feature-icon"><i class="bi bi-boxes" aria-hidden="true"></i></span><div><div class="fw-semibold text-navy">Suppliers</div><div class="small text-muted">List surplus confidentially, control country visibility and receive matched RFQ leads.</div></div></div>
                    <div class="d-grid gap-2 d-sm-flex">
                        <a class="btn btn-primary flex-fill" href="<?= site_url('register/buyer') ?>">I want to buy</a>
                        <a class="btn btn-outline-primary flex-fill" href="<?= site_url('register/supplier') ?>">I want to sell</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section-sm bg-soft border-bottom">
    <div class="container">
        <div class="row g-4 text-center text-md-start">
            <?php foreach ([['bi-patch-check', 'Verified suppliers', '12-module verification: KYC/KYB, compliance, financial, quality, site audit and sanctions screening.'], ['bi-incognito', 'Confidential by design', 'Supplier identities, prices and listings can be hidden or limited to approved countries and buyers.'], ['bi-clipboard-check', 'Optional inspection', 'In-house or third-party inspection with quantity, packaging and authenticity checks.'], ['bi-truck', 'Managed logistics', 'Supplier-direct, BearingCave-managed, confidential or consolidated shipping.']] as [$i, $h, $t]): ?>
            <div class="col-md-6 col-lg-3"><div class="d-flex flex-column flex-md-row gap-3 align-items-center align-items-md-start"><span class="feature-icon flex-shrink-0"><i class="bi <?= $i ?>" aria-hidden="true"></i></span><div><h2 class="h6 mb-1"><?= esc($h) ?></h2><p class="small text-muted mb-0"><?= esc($t) ?></p></div></div></div>
            <?php endforeach ?>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="d-flex justify-content-between align-items-end mb-4 flex-wrap gap-2">
            <div><p class="eyebrow mb-1">Browse</p><h2 class="section-title mb-0">Shop by category</h2></div>
            <a href="<?= site_url('marketplace') ?>" class="fw-semibold">View all inventory <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>
        <div class="row g-3">
            <?php foreach ($categories as $c): ?>
            <div class="col-6 col-md-4 col-lg-3">
                <a class="category-tile" href="<?= site_url('category/' . $c['slug']) ?>">
                    <i class="bi <?= esc($c['icon'] ?: 'bi-box', 'attr') ?>" aria-hidden="true"></i>
                    <span><span class="fw-semibold d-block small"><?= esc($c['name']) ?></span><span class="text-muted small"><?= number_format((int) $c['product_count']) ?> listings</span></span>
                </a>
            </div>
            <?php endforeach ?>
        </div>
    </div>
</section>

<section class="section bg-soft">
    <div class="container">
        <div class="d-flex justify-content-between align-items-end mb-4 flex-wrap gap-2">
            <div><p class="eyebrow mb-1">Live marketplace</p><h2 class="section-title mb-0">Recently listed surplus</h2></div>
            <a href="<?= site_url('marketplace?sort=newest') ?>" class="fw-semibold">See more <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>
        <?php if ($latest): ?>
        <div class="row g-3">
            <?php foreach ($latest as $p): ?><div class="col-6 col-md-4 col-lg-3"><?= view('components/product_card', ['p' => $p, 'viewer' => service('visibility')->current()]) ?></div><?php endforeach ?>
        </div>
        <?php else: ?>
            <div class="bc-card"><?= empty_state('bi-boxes', 'No public listings yet', 'Approved supplier listings will appear here. Some listings are visible only to signed-in or verified buyers in permitted countries.', '<a class="btn btn-primary" href="' . site_url('register/supplier') . '">List your surplus stock</a>') ?></div>
        <?php endif ?>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="row g-5">
            <div class="col-lg-6">
                <p class="eyebrow mb-1">For buyers</p>
                <h2 class="section-title">Source genuine parts at surplus prices</h2>
                <ol class="list-unstyled d-grid gap-3 mt-4">
                    <?php foreach (['Search by part number, OEM number, cross reference, brand or dimensions.', 'Compare stock, price, delivery terms and supplier verification side by side.', 'Send an RFQ — BearingCave matches it to verified suppliers who hold the stock.', 'Accept the best offer, add inspection or logistics, and track the order.'] as $i => $s): ?>
                    <li class="d-flex gap-3"><span class="step-num"><?= $i + 1 ?></span><span class="pt-1"><?= esc($s) ?></span></li>
                    <?php endforeach ?>
                </ol>
                <a class="btn btn-primary mt-2" href="<?= site_url('for-buyers') ?>">Buyer guide</a>
            </div>
            <div class="col-lg-6">
                <p class="eyebrow mb-1">For suppliers</p>
                <h2 class="section-title">Release working capital from dead stock</h2>
                <ol class="list-unstyled d-grid gap-3 mt-4">
                    <?php foreach (['Register free and list surplus lots — your identity stays private by default.', 'Choose where listings are visible and whether prices are shown.', 'Get verified to unlock premium RFQ leads, bidding, per-piece pricing and bulk upload.', 'Ship directly or let BearingCave manage confidential logistics.'] as $i => $s): ?>
                    <li class="d-flex gap-3"><span class="step-num"><?= $i + 1 ?></span><span class="pt-1"><?= esc($s) ?></span></li>
                    <?php endforeach ?>
                </ol>
                <a class="btn btn-outline-primary mt-2" href="<?= site_url('membership') ?>">Compare supplier plans</a>
            </div>
        </div>
    </div>
</section>

<section class="pb-5">
    <div class="container">
        <div class="cta-band p-4 p-lg-5 d-lg-flex align-items-center justify-content-between gap-4">
            <div><h2 class="h3 mb-2">Have surplus automotive inventory?</h2><p class="mb-0 opacity-75">List it confidentially in minutes. Verification and premium features are available when you are ready.</p></div>
            <div class="d-flex gap-2 mt-3 mt-lg-0 flex-shrink-0"><a class="btn btn-light" href="<?= site_url('register/supplier') ?>">Start selling</a><a class="btn btn-outline-light" href="<?= site_url('contact') ?>">Talk to us</a></div>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
