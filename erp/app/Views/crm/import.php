<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php if ($preview === null): ?>
<form method="post" action="<?= site_url('crm/leads/import') ?>" enctype="multipart/form-data" class="card" style="max-width:760px"><div class="card-body">
    <?= csrf_field() ?>
    <label class="form-label required" for="file">Lead sheet (.xlsx or .csv)</label><input type="file" class="form-control mb-2" id="file" name="file" accept=".csv,.xlsx" required>
    <p class="small text-muted mb-1">First row = column names. Recognised columns:</p>
    <p class="small mono"><?= esc(implode(', ', $fields)) ?></p>
    <p class="small text-muted mb-0">Duplicates are found by mobile number (ignoring +91, spaces and leading 0) against existing leads and within the file, and are skipped — nothing is overwritten.</p>
</div><div class="card-footer bg-white text-end"><button class="btn btn-dark" type="submit">Upload &amp; check</button></div></form>
<?php else: $p = $preview; ?>
<div class="row g-3 mb-3">
    <div class="col-4 col-xl-2"><div class="kpi-card"><div class="kpi-label">Rows</div><div class="kpi-value"><?= (int) $p['total'] ?></div></div></div>
    <div class="col-4 col-xl-2"><div class="kpi-card"><div class="kpi-label">Will import</div><div class="kpi-value text-success"><?= (int) ($p['counts']['ok'] ?? 0) ?></div></div></div>
    <div class="col-4 col-xl-2"><div class="kpi-card"><div class="kpi-label">Duplicates</div><div class="kpi-value text-warning"><?= (int) ($p['counts']['duplicate'] ?? 0) ?></div></div></div>
    <div class="col-4 col-xl-2"><div class="kpi-card"><div class="kpi-label">Errors</div><div class="kpi-value text-danger"><?= (int) ($p['counts']['error'] ?? 0) ?></div></div></div>
</div>
<div class="small mb-2">Columns matched: <?php foreach ($p['map'] as $h => $f): ?><span class="badge text-bg-light border"><?= esc($h) ?> → <?= esc($f) ?></span> <?php endforeach ?></div>
<div class="table-card mb-3"><div class="table-responsive"><table class="table small align-middle">
    <thead><tr><th>Row</th><th>Status</th><th>Business</th><th>Mobile</th><th>City</th><th>Note</th></tr></thead><tbody>
    <?php foreach ($p['rows'] as $r): ?><tr class="<?= $r['status'] === 'ok' ? '' : ($r['status'] === 'error' ? 'table-danger' : 'table-warning') ?>"><td><?= (int) $r['row'] ?></td><td><?= esc($r['status']) ?></td><td><?= esc($r['data']['company_name'] ?? $r['data']['contact_person'] ?? '') ?></td><td class="mono"><?= esc($r['data']['mobile'] ?? '') ?></td><td><?= esc($r['data']['city'] ?? '') ?></td><td><?= esc($r['note']) ?></td></tr><?php endforeach ?>
</tbody></table></div></div>
<?php if ($p['total'] > 50): ?><p class="small text-muted">Showing the first 50 rows.</p><?php endif ?>
<form method="post" action="<?= site_url('crm/leads/import/commit') ?>" class="card"><div class="card-body d-flex flex-wrap gap-3 align-items-end">
    <?= csrf_field() ?><input type="hidden" name="file" value="<?= (int) $p['file'] ?>">
    <?php if ($users !== []): ?><div><label class="form-label small" for="assign_to">Assign rows without an "assigned" column to</label><select class="form-select form-select-sm" id="assign_to" name="assign_to"><?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>" <?= (int) $u['id'] === (int) current_user_id() ? 'selected' : '' ?>><?= esc($u['name']) ?></option><?php endforeach ?></select></div><?php endif ?>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="download_errors" value="1" id="dl_err" checked><label class="form-check-label small" for="dl_err">Download skipped rows as Excel</label></div>
    <a class="btn btn-outline-secondary" href="<?= site_url('crm/leads/import') ?>">Start over</a>
    <button class="btn btn-dark" type="submit" <?= ($p['counts']['ok'] ?? 0) > 0 ? '' : 'disabled' ?>>Import <?= (int) ($p['counts']['ok'] ?? 0) ?> leads</button>
</div></form>
<?php endif ?>
<?= $this->endSection() ?>
