<?= $this->extend('layouts/public') ?>
<?= $this->section('content') ?>
<div class="bg-soft border-bottom"><div class="container py-5 text-center">
    <p class="eyebrow mb-1">Supplier membership</p>
    <h1 class="h2">Start free. Get verified when you're ready.</h1>
    <p class="text-muted mx-auto" style="max-width:640px">Every supplier can list surplus stock for free. Verification plus the annual Verified Supplier membership unlocks premium RFQ leads, bidding, per-piece selling, country controls and analytics.</p>
</div></div>
<div class="container py-5">
    <div class="row g-4 justify-content-center">
        <?php foreach ($plans as $plan): $e = $ents[$plan['id']] ?? []; $featured = (bool) $plan['requires_verification']; ?>
        <div class="col-md-6 col-lg-5">
            <div class="plan-card<?= $featured ? ' featured' : '' ?>">
                <div class="d-flex justify-content-between align-items-start"><h2 class="h4 mb-1"><?= esc($plan['name']) ?></h2><?= $featured ? '<span class="badge-verified"><i class="bi bi-patch-check-fill" aria-hidden="true"></i>Badge</span>' : '' ?></div>
                <div class="price my-2"><?= (float) $plan['annual_fee'] > 0 ? money($plan['annual_fee'], $plan['currency'], 0) . '<span class="fs-6 text-muted fw-normal"> / year</span>' : 'Free' ?></div>
                <?php if ((float) $plan['annual_fee'] > 0): ?><p class="small text-muted mb-2">Plus applicable taxes. <?= service('settings_store')->isApproved('tax.subscription_rate_pct') ? '' : pending_badge('Tax treatment pending approval') ?></p><?php endif ?>
                <p class="small text-muted"><?= esc($plan['description']) ?></p>
                <ul class="mt-3">
                    <?php foreach ($features as $key => [$label]): $on = ! empty($e[$key]['is_enabled']); ?>
                        <?php if ($key === 'max_active_products') { continue; } ?>
                        <li><i class="bi <?= $on ? 'bi-check2' : 'bi-dash' ?>" aria-hidden="true"></i><span class="<?= $on ? '' : 'text-muted' ?>"><?= esc(preg_replace('/ \(limit.*\)$/', '', $label)) ?><?= ! $on ? '<span class="visually-hidden"> (not included)</span>' : '' ?></span></li>
                    <?php endforeach ?>
                </ul>
                <a class="btn <?= $featured ? 'btn-primary' : 'btn-outline-primary' ?> w-100 mt-3" href="<?= site_url(auth()->loggedIn() ? 'supplier/membership' : 'register/supplier') ?>"><?= $featured ? 'Apply for verification' : 'Register free' ?></a>
            </div>
        </div>
        <?php endforeach ?>
    </div>
    <div class="row justify-content-center mt-5"><div class="col-lg-10">
        <div class="bc-card"><div class="bc-card-body">
            <h2 class="h5">How verification and membership work together</h2>
            <ol class="small mb-0">
                <li>Register free and complete your company profile.</li>
                <li>Submit documents: KYC/KYB, certifications, financial and quality information.</li>
                <li>BearingCave reviews compliance, finances, risk, quality, a site audit (where required) and sanctions screening.</li>
                <li>Management approval confirms your company as verified — this is independent of payment.</li>
                <li>Pay the annual membership invoice to activate the Verified Supplier badge and premium features.</li>
            </ol>
        </div></div>
    </div></div>
</div>
<?= $this->endSection() ?>
