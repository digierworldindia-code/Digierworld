<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="empty-state card"><div class="card-body"><i class="bi bi-person-badge"></i>
    <p class="mb-1">Your login is not linked to an employee record yet.</p>
    <p class="small text-muted mb-0">Ask HR to open your employee profile and choose your login under “DGR Office login”. After that you will see your attendance, leave and payslips here.</p>
</div></div>
<?= $this->endSection() ?>
