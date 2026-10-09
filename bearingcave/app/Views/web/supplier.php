<?= $this->extend('layouts/public') ?>
<?= $this->section('content') ?>
<?php $c = $company; ?>
<div class="bg-soft border-bottom"><div class="container py-4">
    <nav aria-label="Breadcrumb"><ol class="breadcrumb mb-1"><li class="breadcrumb-item"><a href="<?= site_url('suppliers') ?>">Suppliers</a></li><li class="breadcrumb-item active" aria-current="page"><?= esc($c['trade_name'] ?: $c['legal_name']) ?></li></ol></nav>
    <div class="d-flex flex-wrap align-items-center gap-2"><h1 class="h3 mb-0"><?= esc($c['trade_name'] ?: $c['legal_name']) ?></h1><span class="badge-verified"><i class="bi bi-patch-check-fill" aria-hidden="true"></i>Verified Supplier</span><?= sample_badge($c) ?></div>
    <p class="text-muted mb-0 mt-1"><?= esc(trim(($c['city'] ? $c['city'] . ', ' : '') . country_name($c['country_code']))) ?><?= $c['year_established'] ? ' · Established ' . esc($c['year_established']) : '' ?></p>
</div></div>
<div class="container py-4">
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="bc-card mb-3"><div class="bc-card-body">
                <h2 class="h6">About</h2>
                <p class="small text-muted"><?= esc($c['description'] ?: 'No description provided.') ?></p>
                <dl class="dl-grid small">
                    <?php if ($profile): ?><dt>Supplier type</dt><dd><?= esc(ucwords(str_replace('_', ' ', $profile['supplier_type']))) ?></dd>
                    <?php if ($profile['main_categories']): ?><dt>Categories</dt><dd><?= esc($profile['main_categories']) ?></dd><?php endif ?>
                    <?php if ($profile['brands_handled']): ?><dt>Brands</dt><dd><?= esc($profile['brands_handled']) ?></dd><?php endif ?><?php endif ?>
                    <?php if ($c['website']): ?><dt>Website</dt><dd><a href="<?= esc($c['website'], 'attr') ?>" rel="nofollow noopener" target="_blank"><?= esc(parse_url($c['website'], PHP_URL_HOST) ?: $c['website']) ?></a></dd><?php endif ?>
                </dl>
                <?php if ($showContact): ?><hr><div class="small"><i class="bi bi-telephone me-1" aria-hidden="true"></i><?= esc($c['phone'] ?? '—') ?><br><i class="bi bi-envelope me-1" aria-hidden="true"></i><?= esc($c['email'] ?? '—') ?></div><?php elseif (! auth()->loggedIn()): ?><p class="small text-muted mb-0">Sign in to see contact details shared by this supplier.</p><?php endif ?>
            </div></div>
            <?php if ($certs): ?><div class="bc-card"><div class="bc-card-body"><h2 class="h6">Verified certifications</h2><ul class="list-unstyled small mb-0"><?php foreach ($certs as $ct): ?><li class="mb-1"><i class="bi bi-award text-primary me-1" aria-hidden="true"></i><?= esc($ct['cert_type']) ?><?= $ct['expires_on'] ? ' · valid to ' . fdate($ct['expires_on']) : '' ?></li><?php endforeach ?></ul></div></div><?php endif ?>
        </div>
        <div class="col-lg-8">
            <h2 class="h5 mb-3">Public listings</h2>
            <?php if ($result['items']): ?>
                <div class="row g-3"><?php foreach ($result['items'] as $p): ?><div class="col-6 col-md-4"><?= view('components/product_card', ['p' => $p, 'viewer' => service('visibility')->current()]) ?></div><?php endforeach ?></div>
                <?= pagination_links($result['page'], $result['pages']) ?>
            <?php else: ?><div class="bc-card"><?= empty_state('bi-boxes', 'No public listings', 'This supplier trades confidentially or has no listings visible in your market.') ?></div><?php endif ?>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
