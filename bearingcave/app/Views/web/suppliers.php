<?= $this->extend('layouts/public') ?>
<?= $this->section('content') ?>
<div class="bg-soft border-bottom"><div class="container py-4">
    <h1 class="h3 mb-1">Verified suppliers</h1>
    <p class="text-muted mb-0">Suppliers listed here passed BearingCave verification and chose to publish their profile. Many verified suppliers prefer to trade confidentially — their stock is still searchable in the marketplace.</p>
</div></div>
<div class="container py-4">
    <?php if (! $suppliers): ?>
        <div class="bc-card"><?= empty_state('bi-building', 'No public supplier profiles yet', 'Verified suppliers can opt in to a public profile from their dashboard.', '<a class="btn btn-primary" href="' . site_url('marketplace') . '">Search inventory instead</a>') ?></div>
    <?php else: ?>
    <div class="row g-3">
        <?php foreach ($suppliers as $s): ?>
        <div class="col-md-6 col-lg-4">
            <a class="category-tile flex-column align-items-start" href="<?= site_url('suppliers/' . $s['slug']) ?>">
                <div class="d-flex justify-content-between w-100 gap-2"><span class="fw-semibold text-navy"><?= esc($s['trade_name'] ?: $s['legal_name']) ?></span><span class="badge-verified"><i class="bi bi-patch-check-fill" aria-hidden="true"></i>Verified</span></div>
                <span class="small text-muted"><i class="bi bi-geo-alt" aria-hidden="true"></i> <?= esc(trim(($s['city'] ? $s['city'] . ', ' : '') . country_name($s['country_code']))) ?> <?= sample_badge($s) ?></span>
                <?php if ($s['description']): ?><span class="small text-muted"><?= esc(character_limiter($s['description'], 120)) ?></span><?php endif ?>
            </a>
        </div>
        <?php endforeach ?>
    </div>
    <?php endif ?>
</div>
<?= $this->endSection() ?>
