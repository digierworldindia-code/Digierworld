<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<form method="get" class="d-flex gap-2"><?php if ($wizard): ?><input type="hidden" name="wizard" value="1"><?php endif ?>
    <select name="fy" class="form-select form-select-sm" data-autosubmit aria-label="Financial year"><?php foreach ($fys as $fy): ?><option value="<?= $fy['id'] ?>" <?= (int) $fy['id'] === $fyId ? 'selected' : '' ?>>FY <?= esc($fy['code']) ?></option><?php endforeach ?></select>
</form>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php if ($wizard): ?><?= view('settings/_wizard', ['current' => 'sequences']) ?><?php endif ?>
<div class="alert alert-info small"><i class="bi bi-123"></i> Each document type has its <strong>own</strong> series — a Cash Memo never consumes a Tax Invoice number. Statutory series follow GST Rule 46: consecutive, unique within the financial year, at most <strong>16 characters</strong>, using only letters, digits, “-” and “/”. Numbers are assigned only when a document is <strong>finalized</strong> (drafts use an internal DRAFT id) and are never reused. Continuing from an old book? Enter the <em>last number used</em> (e.g. 121 → the next invoice will be 122).</div>
<div class="table-card"><div class="table-responsive">
<table class="table align-middle small">
    <thead><tr><th style="width:22%">Series</th><th>Prefix · padding · suffix · last number used → next number</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><strong><?= esc($r['name']) ?></strong><div class="text-muted"><?= esc(doc_type_label($r['doc_type'])) ?> · <?= $r['is_statutory'] ? '<span class="badge bg-dark">statutory ≤16</span>' : '<span class="badge bg-light text-dark border">internal</span>' ?></div></td>
            <td>
                <form method="post" action="<?= site_url('settings/sequences/' . $r['id']) ?>" class="row g-2 align-items-center" data-confirm="Save numbering for <?= esc($r['name'], 'attr') ?>?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="name" value="<?= esc($r['name']) ?>">
                    <div class="col-3"><input class="form-control form-control-sm mono" name="prefix" value="<?= esc($r['prefix']) ?>" maxlength="16" aria-label="Prefix"></div>
                    <div class="col-2"><input type="number" min="1" max="8" class="form-control form-control-sm" name="padding" value="<?= (int) $r['padding'] ?>" aria-label="Padding"></div>
                    <div class="col-2"><input class="form-control form-control-sm mono" name="suffix" value="<?= esc($r['suffix']) ?>" maxlength="10" aria-label="Suffix"></div>
                    <div class="col-2"><input type="number" min="0" class="form-control form-control-sm" name="last_used" value="<?= (int) $r['next_number'] - 1 ?>" aria-label="Last used number"></div>
                    <div class="col-3"><span class="mono fw-semibold"><?= esc($r['next_label']) ?></span> <span class="text-muted">(<?= strlen($r['next_label']) ?> chars)</span></div>
                    <div class="col-12 col-xl-auto"><span class="text-muted">Issued: <?= $r['max_used'] > 0 ? (int) $r['max_used'] : 'none' ?></span> <button class="btn btn-sm btn-dark ms-2">Save</button></div>
                </form>
            </td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div></div>
<?php if ($wizard): ?><div class="sticky-actions"><a href="<?= site_url('settings/templates?wizard=1') ?>" class="btn btn-dark">Continue to invoice template →</a></div><?php endif ?>
<?= $this->endSection() ?>
