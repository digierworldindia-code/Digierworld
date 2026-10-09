<?= $this->extend('layouts/auth') ?>
<?= $this->section('content') ?>
<div class="bc-card"><div class="bc-card-body p-4 text-center">
    <div class="feature-icon mb-3"><i class="bi bi-envelope-check" aria-hidden="true"></i></div>
    <h1 class="h4">Check your email</h1>
    <p class="text-muted">If an account exists for that address, a one-time sign-in link has been sent. The link expires in one hour.</p>
    <p class="small text-muted mb-0">Not received it? Email delivery depends on the platform's mail configuration — contact support if needed.</p>
</div></div>
<?= $this->endSection() ?>
