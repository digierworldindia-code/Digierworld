<?= $this->extend('layouts/app') ?>
<?php
$st = $status;
$state = match (true) {
    ! $st['enabled']                 => ['Not configured', 'secondary'],
    $identity === null               => ['Disconnected', 'danger'],
    $st['failed'] + $st['dead'] > 0  => ['Connected — some rows failing', 'warning'],
    default                          => ['Connected', 'success'],
};
?>
<?= $this->section('pageActions') ?>
<?php if ($canManage): ?>
<form method="post" action="<?= site_url('crm/sync/run') ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-gold btn-sm" <?= $st['enabled'] ? '' : 'disabled' ?>><i class="bi bi-arrow-repeat"></i> Sync now</button></form>
<form method="post" action="<?= site_url('crm/sync/retry') ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-outline-dark btn-sm" <?= $st['failed'] + $st['dead'] > 0 ? '' : 'disabled' ?>>Retry failed (<?= $st['failed'] + $st['dead'] ?>)</button></form>
<?php endif ?>
<?php if (can('crm.export')): ?><a class="btn btn-outline-dark btn-sm" href="<?= site_url('crm/export') ?>"><i class="bi bi-file-earmark-excel"></i> Export instead</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><div class="kpi-card accent"><div class="kpi-label">Status</div><div class="kpi-value"><span class="badge text-bg-<?= $state[1] ?>"><?= $state[0] ?></span></div><div class="kpi-sub"><?= $identity ? 'as ' . esc($identity) : esc((string) $clientError) ?></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-label">Last successful sync</div><div class="kpi-value fs-5"><?= $st['last_success'] ? dmy($st['last_success'], true) : '—' ?></div><div class="kpi-sub">Last run <?= $st['last_run'] ? dmy($st['last_run'], true) : '—' ?></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-label">Waiting</div><div class="kpi-value"><?= (int) $st['pending'] ?></div><div class="kpi-sub">records queued</div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card <?= $st['failed'] + $st['dead'] ? 'border-danger' : '' ?>"><div class="kpi-label">Failed rows</div><div class="kpi-value <?= $st['failed'] + $st['dead'] ? 'text-danger' : '' ?>"><?= (int) $st['failed'] + (int) $st['dead'] ?></div><div class="kpi-sub"><?= (int) $st['dead'] ?> gave up after 8 tries</div></div></div>
</div>
<div class="alert alert-light border small"><i class="bi bi-shield-check"></i> MySQL is the source of truth. Every CRM save is committed first and queued here; if Google is down or the quota is hit, nothing is lost — jobs retry with increasing delays. Sync is one-way (database → sheet); edits made in the sheet are overwritten. <?= $st['last_error'] ? '<br><strong>Last error:</strong> ' . esc($st['last_error']) . ' (' . dmy($st['last_error_at'], true) . ')' : '' ?></div>

<?php if ($canManage): ?>
<form method="post" action="<?= site_url('crm/sync/settings') ?>" class="card mb-3"><div class="card-body small">
    <?= csrf_field() ?>
    <div class="d-flex justify-content-between align-items-start"><div class="card-section-title">Connection</div>
        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="enabled" value="1" id="sy_en" <?= ! empty($settings['enabled']) ? 'checked' : '' ?>><label class="form-check-label" for="sy_en">Sync enabled</label></div></div>
    <div class="row g-2">
        <div class="col-md-6"><label class="form-label" for="sy_id">Spreadsheet ID or URL</label><input class="form-control mono" id="sy_id" name="spreadsheet_id" value="<?= esc((string) ($settings['spreadsheet_id'] ?? '')) ?>" placeholder="1AbC…xyz"></div>
        <div class="col-md-4"><label class="form-label" for="sy_cred">Service-account key file (on the server)</label><input class="form-control mono" id="sy_cred" name="credentials_path" value="<?= esc((string) ($settings['credentials_path'] ?? '')) ?>" placeholder="writable/credentials/sheets.service-account.json">
            <div class="form-text"><?= $credPath ? (is_file($credPath) ? 'Key file found.' : 'Key file not found at this path.') : 'Using .env google.credentialsPath if set.' ?> Never upload the key through the browser.</div></div>
        <div class="col-md-2"><label class="form-label" for="sy_fr">Every (minutes)</label><input type="number" min="5" max="1440" class="form-control" id="sy_fr" name="frequency_minutes" value="<?= (int) ($settings['frequency_minutes'] ?? 5) ?>"></div>
    </div>
    <div class="card-section-title mt-3">Worksheets &amp; columns</div>
    <p class="text-muted mb-2">Column A always holds the stable record ID used to update the same row. Do-Not-Call numbers are masked. Avoid adding amounts to sheets shared widely.</p>
    <div class="row g-2">
    <?php foreach ($columns as $ws => $cols): $enabled = $settings['field_map'][$ws] ?? array_keys($cols); ?>
        <div class="col-md-6 col-xl-4"><div class="border rounded p-2 h-100">
            <input class="form-control form-control-sm mb-1 fw-semibold" name="ws[<?= $ws ?>]" value="<?= esc((string) $settings['worksheets'][$ws]) ?>" aria-label="Worksheet name for <?= $ws ?>">
            <?php foreach ($cols as $k => $label): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="cols[<?= $ws ?>][]" value="<?= $k ?>" id="c_<?= $ws . $k ?>" <?= $k === 'id' ? 'checked disabled' : (in_array($k, $enabled, true) ? 'checked' : '') ?>><label class="form-check-label" for="c_<?= $ws . $k ?>"><?= esc($label) ?></label></div><?php endforeach ?>
            <input type="hidden" name="cols[<?= $ws ?>][]" value="id">
        </div></div>
    <?php endforeach ?>
    </div>
    <div class="d-flex flex-wrap gap-2 mt-3"><button class="btn btn-dark" type="submit">Save settings</button></div>
</div></form>
<div class="d-flex flex-wrap gap-2 mb-3">
    <form method="post" action="<?= site_url('crm/sync/test') ?>"><?= csrf_field() ?><button class="btn btn-outline-dark btn-sm" <?= $st['enabled'] ? '' : 'disabled' ?>><i class="bi bi-plug"></i> Test connection &amp; create tabs</button></form>
    <form method="post" action="<?= site_url('crm/sync/queue-all') ?>"><?= csrf_field() ?><button class="btn btn-outline-dark btn-sm"><i class="bi bi-collection"></i> Queue all records</button></form>
    <span class="small text-muted align-self-center">Setup guide: <code>docs/05-GOOGLE-SHEETS.md</code> in the installation folder.</span>
</div>
<?php endif ?>

<div class="row g-3">
<div class="col-xl-7"><div class="table-card"><div class="card-header bg-white"><strong>Queue</strong> <span class="small text-muted">(not yet synced)</span></div><div class="table-responsive"><table class="table small align-middle mb-0">
    <thead><tr><th>Record</th><th>Status</th><th class="num">Tries</th><th>Next try</th><th>Last error</th></tr></thead><tbody>
    <?php foreach ($jobs as $j): ?><tr><td><?= esc($j['worksheet']) ?> #<?= (int) $j['entity_id'] ?></td><td><?= status_badge($j['status']) ?></td><td class="num"><?= (int) $j['attempts'] ?></td><td class="text-nowrap"><?= dmy($j['next_attempt_at'], true) ?></td><td class="text-danger"><?= esc(mb_strimwidth((string) $j['last_error'], 0, 120, '…')) ?></td></tr><?php endforeach ?>
    <?php if ($jobs === []): ?><tr><td colspan="5" class="text-muted">Queue is empty — everything is in the sheet.</td></tr><?php endif ?>
</tbody></table></div></div></div>
<div class="col-xl-5"><div class="table-card"><div class="card-header bg-white"><strong>Recent runs</strong></div><table class="table small mb-0"><tbody>
    <?php foreach ($runs as $r): ?><tr><td class="text-nowrap"><?= dmy($r['started_at'], true) ?></td><td><?= esc($r['trigger_source']) ?></td><td><?= esc((string) $r['message']) ?></td></tr><?php endforeach ?>
    <?php if ($runs === []): ?><tr><td class="text-muted">No runs yet.</td></tr><?php endif ?>
</tbody></table></div></div>
</div>
<?= $this->endSection() ?>
