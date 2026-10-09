<?= $this->extend('layouts/public') ?>
<?= $this->section('content') ?>
<div class="container py-5">
    <div class="row g-5">
        <div class="col-lg-5">
            <p class="eyebrow mb-1">Contact</p>
            <h1 class="h2">Talk to the BearingCave team</h1>
            <p class="text-muted">Questions about selling surplus stock, sourcing parts, verification, membership, inspection or logistics — we'll route your message to the right specialist.</p>
            <ul class="list-unstyled d-grid gap-3 mt-4">
                <li class="d-flex gap-3"><span class="feature-icon"><i class="bi bi-envelope" aria-hidden="true"></i></span><div><div class="fw-semibold">Email</div><div class="text-muted small"><?= esc(policy('platform.support_email', '')) ?></div></div></li>
                <?php if ($ph = policy('platform.support_phone')): ?><li class="d-flex gap-3"><span class="feature-icon"><i class="bi bi-telephone" aria-hidden="true"></i></span><div><div class="fw-semibold">Phone</div><div class="text-muted small"><?= esc($ph) ?></div></div></li><?php endif ?>
                <li class="d-flex gap-3"><span class="feature-icon"><i class="bi bi-shield-lock" aria-hidden="true"></i></span><div><div class="fw-semibold">Confidential</div><div class="text-muted small">Details you share are used only to respond to your enquiry.</div></div></li>
            </ul>
        </div>
        <div class="col-lg-7">
            <div class="bc-card"><div class="bc-card-body p-4">
                <form method="post" action="<?= site_url('contact') ?>" novalidate>
                    <?= csrf_field() ?>
                    <div class="visually-hidden" aria-hidden="true"><label for="website">Website</label><input type="text" id="website" name="website" tabindex="-1" autocomplete="off"></div>
                    <div class="row g-3">
                        <div class="col-md-6"><?= field('name', 'Your name', null, ['required' => true, 'class' => '', 'attrs' => 'autocomplete="name"']) ?></div>
                        <div class="col-md-6"><?= field('company_name', 'Company', null, ['class' => '', 'attrs' => 'autocomplete="organization"']) ?></div>
                        <div class="col-md-6"><?= field('email', 'Work email', null, ['type' => 'email', 'required' => true, 'class' => '', 'attrs' => 'autocomplete="email"']) ?></div>
                        <div class="col-md-6"><?= field('phone', 'Phone', null, ['type' => 'tel', 'class' => '', 'attrs' => 'autocomplete="tel"']) ?></div>
                        <div class="col-md-6"><?= select_field('country_code', 'Country', country_options(), null, ['placeholder' => 'Select country', 'class' => '']) ?></div>
                        <div class="col-md-6"><?= select_field('enquiry_type', 'Topic', ['general' => 'General', 'buyer' => 'Buying parts', 'supplier' => 'Selling surplus', 'membership' => 'Membership & verification', 'inspection' => 'Inspection', 'logistics' => 'Logistics', 'partnership' => 'Partnership'], 'general', ['required' => true, 'class' => '']) ?></div>
                        <div class="col-12"><?= textarea_field('message', 'Message', null, ['required' => true, 'rows' => 5, 'class' => '']) ?></div>
                    </div>
                    <button class="btn btn-primary mt-3" type="submit">Send message</button>
                </form>
            </div></div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
