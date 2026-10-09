<?= $this->extend('layouts/auth') ?>
<?= $this->section('content') ?>
<div class="text-center mb-4"><h1 class="h3">Create your free business account</h1><p class="text-muted">Choose how your company will use BearingCave. You can add colleagues later.</p></div>
<div class="row g-3">
    <div class="col-md-6"><a class="category-tile flex-column align-items-start p-4" href="<?= site_url('register/buyer') ?>"><span class="feature-icon mb-2"><i class="bi bi-search" aria-hidden="true"></i></span><span class="fw-semibold text-navy fs-5">I'm a buyer</span><span class="small text-muted">Search inventory, send RFQs, compare offers and place orders with inspection and logistics options.</span><span class="btn btn-primary btn-sm mt-3">Register as buyer</span></a></div>
    <div class="col-md-6"><a class="category-tile flex-column align-items-start p-4" href="<?= site_url('register/supplier') ?>"><span class="feature-icon mb-2"><i class="bi bi-boxes" aria-hidden="true"></i></span><span class="fw-semibold text-navy fs-5">I'm a supplier</span><span class="small text-muted">List surplus, obsolete and slow-moving stock confidentially and reach buyers worldwide.</span><span class="btn btn-outline-primary btn-sm mt-3">Register as supplier</span></a></div>
</div>
<p class="text-center small mt-4">Already registered? <a href="<?= site_url('login') ?>">Sign in</a></p>
<?= $this->endSection() ?>
