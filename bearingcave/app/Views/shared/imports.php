<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Bulk inventory upload', 'subtitle' => 'Upload CSV or Excel, map your columns, review errors and duplicates, then confirm. Nothing is imported until you confirm.', 'actions' => '<a class="btn btn-light btn-sm" href="' . site_url($base . '/template') . '"><i class="bi bi-file-earmark-excel me-1" aria-hidden="true"></i>Download template</a>']) ?>
<div class="row g-4">
    <div class="col-xl-4">
        <div class="bc-card"><div class="bc-card-header"><h2>New upload</h2></div><div class="bc-card-body">
            <form method="post" action="<?= site_url($base) ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <?php if ($area === 'admin'): ?><?= select_field('company_id', 'Import on behalf of supplier', $suppliers, null, ['required' => true, 'placeholder' => 'Select supplier']) ?><?php endif ?>
                <label class="form-label" for="imp">CSV / XLSX file (max <?= (int) policy('imports.max_rows', 5000) ?> rows)</label>
                <input id="imp" class="form-control mb-3" type="file" name="file" accept=".csv,.xlsx,.xls" required>
                <button class="btn btn-primary w-100" type="submit">Upload &amp; map columns</button>
            </form>
            <hr>
            <p class="small-caps mb-1">Template columns</p>
            <p class="small text-muted mb-0"><?php $req = array_keys(array_filter($fields, static fn ($f) => $f[1])); ?>Required: <span class="part-no"><?= esc(implode(', ', $req)) ?></span>. Your own headings can be mapped on the next screen.</p>
        </div></div>
    </div>
    <div class="col-xl-8">
        <div class="bc-card"><div class="bc-card-header"><h2>Import history</h2></div>
        <?php if (! $jobs): ?><?= empty_state('bi-file-earmark-spreadsheet', 'No imports yet') ?><?php else: ?>
            <div class="table-responsive"><table class="table table-bc table-stack mb-0"><thead><tr><th>File</th><th>Uploaded</th><th class="text-end">Rows</th><th class="text-end">Valid</th><th class="text-end">Errors</th><th class="text-end">Duplicates</th><th class="text-end">Imported</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($jobs as $j): ?><tr><td data-label="File"><a href="<?= site_url($base . '/' . $j['uuid']) ?>"><?= esc($j['original_name']) ?></a></td><td data-label="Uploaded" class="small"><?= fdt($j['created_at']) ?></td><td data-label="Rows" class="text-end"><?= (int) $j['total_rows'] ?></td><td data-label="Valid" class="text-end"><?= (int) $j['valid_rows'] ?></td><td data-label="Errors" class="text-end"><?= (int) $j['error_rows'] ?></td><td data-label="Duplicates" class="text-end"><?= (int) $j['duplicate_rows'] ?></td><td data-label="Imported" class="text-end"><?= (int) $j['imported_rows'] ?></td><td data-label="Status"><?= status_badge($j['status']) ?></td></tr><?php endforeach ?>
            </tbody></table></div><?= $pager->links() ?>
        <?php endif ?></div>
    </div>
</div>
<?= $this->endSection() ?>
