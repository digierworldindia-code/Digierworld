<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Your account manager']) ?>
<div class="row"><div class="col-lg-6"><div class="bc-card"><div class="bc-card-body">
<?php if (! $entitled): ?>
    <?= empty_state('bi-person-badge', 'Dedicated account manager', 'Verified Suppliers get a named BearingCave account manager for onboarding, listings and RFQ support.', '<a class="btn btn-primary btn-sm" href="' . site_url('supplier/membership') . '">View membership</a>') ?>
<?php elseif (! $manager): ?>
    <?= empty_state('bi-hourglass-split', 'Assignment pending', 'BearingCave will assign your account manager shortly. Meanwhile contact ' . policy('platform.support_email') . '.') ?>
<?php else: ?>
    <div class="d-flex gap-3 align-items-center"><span class="feature-icon" style="width:56px;height:56px"><i class="bi bi-person" aria-hidden="true"></i></span><div><div class="fw-semibold fs-5"><?= esc($manager->username ?: 'BearingCave account manager') ?></div><div><a href="mailto:<?= esc($manager->email, 'attr') ?>"><?= esc($manager->email) ?></a></div></div></div>
<?php endif ?>
</div></div></div></div>
<?= $this->endSection() ?>
