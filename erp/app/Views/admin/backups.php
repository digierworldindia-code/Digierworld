<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="row g-3">
<div class="col-xl-8">
    <div class="table-card"><div class="table-responsive"><table class="table small align-middle mb-0">
        <thead><tr><th>Backup</th><th>Created</th><th class="num">Size</th><th></th></tr></thead><tbody>
        <?php foreach ($backups as $b): ?>
        <tr><td class="mono"><?= esc($b['name']) ?><div class="text-muted"><?= str_ends_with($b['name'], '.zip') ? 'Private files (CVs, payslips, receipts, logos)' : 'Database (gzip SQL)' ?></div></td><td><?= date('d-m-Y h:i A', $b['time']) ?></td>
            <td class="num"><?= number_format($b['size'] / 1048576, 2) ?> MB</td><td class="text-end"><a class="btn btn-sm btn-outline-dark" href="<?= site_url('admin/backups/' . rawurlencode($b['name'])) ?>"><i class="bi bi-download"></i> Download</a></td></tr>
        <?php endforeach ?>
        <?php if ($backups === []): ?><tr><td colspan="4"><div class="empty-state"><i class="bi bi-database"></i>No backups yet.</div></td></tr><?php endif ?>
    </tbody></table></div></div>
</div>
<div class="col-xl-4">
    <form method="post" action="<?= site_url('admin/backups') ?>" class="card mb-3"><div class="card-body small"><?= csrf_field() ?>
        <div class="card-section-title">Back up now</div>
        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="files" value="1" id="bk_files"><label class="form-check-label" for="bk_files">Include private files (larger)</label></div>
        <button class="btn btn-dark btn-sm w-100">Create backup</button>
    </div></form>
    <div class="card"><div class="card-body small">
        <div class="card-section-title">Keep backups safe</div>
        <ul class="mb-0">
            <li>Schedule daily: <code>php spark dgr:backup --files</code> (keeps the newest 14).</li>
            <li>Copy backups off the server (download here or by SFTP) — a backup on the same disk does not protect against server loss.</li>
            <li>Store the <code>encryption.key</code> from <code>.env</code> separately. Without it, encrypted bank numbers and CIF in the backup cannot be read.</li>
            <li>Backups contain salaries, CVs and client data: share them only with people allowed to see all of it.</li>
            <li>Restore into an empty database: <code>gunzip -c FILE.sql.gz | mysql -u USER -p DB</code>.</li>
        </ul>
    </div></div>
</div>
</div>
<?= $this->endSection() ?>
