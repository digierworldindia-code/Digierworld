<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<p class="small text-muted">Passwords and API keys are never stored in the database or shown here. They are set in the server's <code>.env</code> file by the administrator (see the deployment guide). This page only shows status and lets you test.</p>
<div class="row g-3">
<div class="col-lg-6">
    <div class="card h-100"><div class="card-body">
        <div class="d-flex justify-content-between align-items-start"><div class="card-section-title">Email (SMTP)</div><?= $smtp['configured'] ? '<span class="badge badge-success">Configured</span>' : '<span class="badge badge-neutral">Not configured</span>' ?></div>
        <dl class="row small mb-2">
            <dt class="col-4">Protocol</dt><dd class="col-8"><?= esc($smtp['protocol']) ?></dd>
            <dt class="col-4">Host</dt><dd class="col-8"><?= esc($smtp['host'] ?: '—') ?><?= $smtp['host'] ? ':' . (int) $smtp['port'] . ' ' . esc((string) $smtp['crypto']) : '' ?></dd>
            <dt class="col-4">From</dt><dd class="col-8"><?= esc($smtp['from'] ?: '—') ?></dd>
            <dt class="col-4">Username</dt><dd class="col-8 masked"><?= esc($smtp['user'] ?: '—') ?></dd>
            <dt class="col-4">Last test</dt><dd class="col-8"><?= $smtp['lastTest'] ? esc($smtp['lastTest']['summary']) . ' · ' . dmy($smtp['lastTest']['occurred_at'], true) : '—' ?></dd>
        </dl>
        <p class="small text-muted">Used for reminders, login codes and password links. When not configured, those features fall back to in-app notifications only.</p>
        <form method="post" action="<?= site_url('settings/integrations/smtp/test') ?>"><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm" type="submit" <?= $smtp['configured'] ? '' : 'disabled' ?>><i class="bi bi-envelope"></i> Send test email to <?= esc($myEmail) ?></button></form>
    </div></div>
</div>
<div class="col-lg-6">
    <div class="card h-100"><div class="card-body">
        <div class="card-section-title">Google Sheets sync &amp; AI services</div>
        <p class="small text-muted mb-0">These are configured with the CRM (Google Sheets) and HR (AI CV evaluation) modules. Their status will appear here once those modules are enabled.</p>
    </div></div>
</div>
</div>
<?= $this->endSection() ?>
