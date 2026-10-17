<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="d-flex gap-2 mb-3">
    <span class="badge bg-success fs-6"><?= (int) ($counts['ok'] ?? 0) ?> OK</span>
    <span class="badge bg-warning text-dark fs-6"><?= (int) ($counts['warn'] ?? 0) ?> warnings</span>
    <span class="badge bg-danger fs-6"><?= (int) ($counts['fail'] ?? 0) ?> failures</span>
</div>
<div class="row g-3">
<?php foreach ($grouped as $section => $rows): ?>
    <div class="col-xl-6"><div class="table-card">
        <div class="p-3 border-bottom"><strong><?= esc($section) ?></strong></div>
        <table class="table table-sm small mb-0 align-middle">
        <?php foreach ($rows as [, $label, $status, $detail]): ?>
            <tr>
                <td style="width:36px" class="text-center"><?= $status === 'ok' ? '<i class="bi bi-check-circle-fill text-success" aria-label="ok"></i>' : ($status === 'warn' ? '<i class="bi bi-exclamation-triangle-fill text-warning" aria-label="warning"></i>' : '<i class="bi bi-x-octagon-fill text-danger" aria-label="failure"></i>') ?></td>
                <td class="fw-semibold"><?= esc($label) ?></td>
                <td class="text-muted"><?= esc($detail) ?></td>
            </tr>
        <?php endforeach ?>
        </table>
    </div></div>
<?php endforeach ?>
</div>
<p class="small text-muted mt-3">Secrets (API keys, SMTP password, encryption key, bank numbers) are never displayed here — only whether they are configured. CLI equivalent: <code>php spark dgr:diagnostics</code>.</p>
<?= $this->endSection() ?>
