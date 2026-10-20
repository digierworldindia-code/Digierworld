<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php if ($preview === null): ?>
<div class="row g-3">
    <div class="col-lg-6"><div class="card"><div class="card-body">
        <div class="card-section-title">1. Download the template</div>
        <p class="small">Fill one row per client/vendor. Headers are matched by name (case-insensitive). Required: <code>name</code>.</p>
        <a href="<?= site_url('contacts/import/template') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-excel"></i> Download .xlsx template</a>
        <div class="card-section-title mt-4">2. Upload & preview</div>
        <form method="post" action="<?= site_url('contacts/import') ?>" enctype="multipart/form-data"><?= csrf_field() ?>
            <input type="file" name="file" accept=".xlsx,.csv" class="form-control mb-2" required aria-label="Import file">
            <button class="btn btn-primary">Validate file</button>
        </form>
        <p class="small text-muted mt-2">Nothing is saved until you confirm the preview. Every row is validated (GSTIN checksum, state, PIN, email, duplicates by GSTIN/phone).</p>
    </div></div></div>
    <div class="col-lg-6"><div class="card"><div class="card-body small"><div class="card-section-title">Columns</div><code><?= esc(implode(', ', $columns)) ?></code></div></div></div>
</div>
<?php else: ?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <span class="badge badge-success fs-6"><?= $preview['counts']['ok'] ?> ready</span>
    <span class="badge badge-warning fs-6"><?= $preview['counts']['duplicate'] ?> possible duplicates</span>
    <span class="badge badge-danger fs-6"><?= $preview['counts']['error'] ?> errors</span>
    <?php if ($preview['truncated']): ?><span class="badge badge-neutral fs-6">Only first 2,000 rows processed</span><?php endif ?>
</div>
<form method="post" action="<?= site_url('contacts/import/commit') ?>" class="mb-3" data-confirm="Import the valid rows now?"><?= csrf_field() ?>
    <input type="hidden" name="file_id" value="<?= (int) $fileId ?>">
    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="include_duplicates" value="1" id="incdup"><label class="form-check-label small" for="incdup">Also import rows flagged as possible duplicates</label></div>
    <button class="btn btn-primary">Import <?= $preview['counts']['ok'] ?> valid row(s)</button> <a href="<?= site_url('contacts/import') ?>" class="btn btn-outline-secondary">Start over</a>
    <div class="small text-muted mt-1">Rows with errors are skipped and returned to you as an Excel error file.</div>
</form>
<div class="table-card"><div class="u-mxh-60vh table-responsive">
<table class="table table-sm small mb-0"><thead class="sticky-top"><tr><th>Row</th><th>Status</th><th>Name</th><th>Phone</th><th>GSTIN</th><th>State</th><th>Message</th></tr></thead><tbody>
<?php foreach ($preview['rows'] as $r): ?>
    <tr class="<?= $r['status'] === 'error' ? 'table-danger' : ($r['status'] === 'duplicate' ? 'table-warning' : '') ?>"><td><?= $r['row'] ?></td><td><?= esc($r['status']) ?></td><td><?= esc($r['raw']['name'] ?? '') ?></td><td><?= esc($r['raw']['phone'] ?? '') ?></td><td class="mono"><?= esc($r['raw']['gstin'] ?? '') ?></td><td><?= esc($r['raw']['state_code'] ?? '') ?></td><td><?= esc($r['message']) ?></td></tr>
<?php endforeach ?>
</tbody></table></div></div>
<?php endif ?>
<?= $this->endSection() ?>
