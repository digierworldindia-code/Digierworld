<?= $this->extend('layouts/auth') ?>
<?= $this->section('content') ?>
<div class="bc-card"><div class="bc-card-body p-4 text-center">
    <h1 class="h4">Your login is not linked to a company</h1>
    <p class="text-muted">Ask your company administrator to add you to their BearingCave team, or contact support.</p>
    <a class="btn btn-primary" href="<?= site_url('contact') ?>">Contact support</a> <a class="btn btn-light" href="<?= site_url('logout') ?>">Sign out</a>
</div></div>
<?= $this->endSection() ?>
