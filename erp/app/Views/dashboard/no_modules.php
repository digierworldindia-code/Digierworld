<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="card mw-820">
    <div class="card-body empty-state">
        <i class="bi bi-person-lock"></i>
        <h2 class="h5">No modules are assigned to your account yet</h2>
        <p class="mb-0">You are signed in, but your role does not give access to any page. Ask the owner or an administrator to assign you a role in Administration › Users.</p>
    </div>
</div>
<?= $this->endSection() ?>
