<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php if ($wizard): ?><?= view('settings/_wizard', ['current' => 'branding']) ?><?php endif ?>
<div class="alert alert-info small"><i class="bi bi-shield-lock"></i> Upload your <strong>official</strong> files. They are stored privately and used exactly as uploaded — never redrawn, recoloured or distorted (only scaled proportionally when printed). Uploading a signature does <strong>not</strong> let staff sign documents; that is controlled by the <a href="<?= site_url('settings/signature-policy') ?>">Signature Policy</a> and the <code>billing.apply_signature</code> permission.</div>
<div class="row g-3">
<?php foreach ($types as $type => [$label, $help]): $list = $assets[$type] ?? []; $active = array_values(array_filter($list, static fn ($a) => (int) $a['is_active'] === 1))[0] ?? null; ?>
    <div class="col-lg-6 col-xxl-4">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="fw-semibold"><?= esc($label) ?></div><div class="small text-muted"><?= esc($help) ?></div></div>
                <?= $active ? '<span class="badge badge-success">v' . (int) $active['version'] . ' active</span>' : '<span class="badge badge-neutral">not uploaded</span>' ?>
            </div>
            <div class="asset-preview my-3">
                <?php if ($active && $active['on_disk']): ?><img src="<?= site_url('settings/branding/' . $active['id'] . '/preview') ?>" alt="<?= esc($label) ?> preview"><?php elseif ($active): ?><span class="small text-danger text-center px-2"><i class="bi bi-exclamation-triangle"></i> The file for this version is missing on the server (writable/uploads). Documents print without it. Upload it again below, or restore the uploads backup.</span><?php else: ?><span class="small text-muted">No file</span><?php endif ?>
            </div>
            <?php if ($active): ?>
                <div class="small text-muted mb-2"><?= esc($active['original_name']) ?> · <?= (int) $active['width_px'] ?>×<?= (int) $active['height_px'] ?> px · <?= number_format($active['size_bytes'] / 1024, 1) ?> KB · sha256 <span class="mono"><?= esc(substr($active['sha256'], 0, 10)) ?>…</span></div>
                <form method="post" action="<?= site_url('settings/branding/' . $active['id']) ?>" class="border rounded p-2 mb-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="is_active" value="1">
                    <div class="row g-2">
                        <div class="col-7"><label class="form-label small" for="pos<?= $active['id'] ?>">Position</label>
                            <select class="form-select form-select-sm" id="pos<?= $active['id'] ?>" name="position_preset">
                                <?php foreach (['top_left' => 'Top left', 'top_center' => 'Top centre', 'top_right' => 'Top right', 'bottom_left' => 'Bottom left', 'bottom_center' => 'Bottom centre', 'bottom_right' => 'Bottom right'] as $k => $l): ?><option value="<?= $k ?>" <?= $active['position_preset'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?>
                            </select></div>
                        <div class="col-5"><label class="form-label small" for="w<?= $active['id'] ?>">Width (mm)</label><input type="number" step="0.5" min="10" max="120" class="form-control form-control-sm" id="w<?= $active['id'] ?>" name="display_width_mm" value="<?= esc($active['display_width_mm']) ?>"></div>
                        <?php if ($type === 'upi_qr'): ?>
                        <div class="col-12"><label class="form-label small" for="qb<?= $active['id'] ?>">QR belongs to account</label><select class="form-select form-select-sm" id="qb<?= $active['id'] ?>" name="bank_account_id"><option value="">—</option><?php foreach ($banks as $b): ?><option value="<?= $b['id'] ?>" <?= (int) $active['bank_account_id'] === (int) $b['id'] ? 'selected' : '' ?>><?= esc($b['display_name']) ?></option><?php endforeach ?></select></div>
                        <?php endif ?>
                        <div class="col-12"><span class="form-label small d-block">Show on</span>
                            <?php $en = json_decode((string) $active['enabled_doc_types'], true) ?: []; foreach ($docTypes as $dk => $dt): ?>
                                <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="enabled_doc_types[]" value="<?= $dk ?>" id="en<?= $active['id'] . $dk ?>" <?= in_array($dk, $en, true) ? 'checked' : '' ?>><label class="form-check-label small" for="en<?= $active['id'] . $dk ?>"><?= esc($dt['label']) ?></label></div>
                            <?php endforeach ?></div>
                    </div>
                    <button class="btn btn-sm btn-primary mt-2">Save settings</button>
                </form>
            <?php endif ?>
            <form method="post" action="<?= site_url('settings/branding') ?>" enctype="multipart/form-data" class="d-flex gap-2" <?= $active ? 'data-confirm="Replace the active ' . esc($label, 'attr') . '? The current version stays in history and on already-issued documents."' : '' ?>>
                <?= csrf_field() ?>
                <input type="hidden" name="asset_type" value="<?= $type ?>">
                <input type="file" name="file" accept="image/png,image/jpeg,image/webp" class="form-control form-control-sm" required aria-label="<?= esc($label) ?> file">
                <button class="btn btn-sm btn-outline-secondary text-nowrap"><?= $active ? 'Replace' : 'Upload' ?></button>
            </form>
            <div class="form-text">PNG (transparent background recommended for signature/stamp), JPG or WEBP, max 3 MB.</div>
            <?php if (count($list) > 1): ?>
            <details class="mt-2 small"><summary>Version history (<?= count($list) ?>)</summary>
                <?php foreach ($list as $h): ?><div class="d-flex justify-content-between border-bottom py-1"><span>v<?= (int) $h['version'] ?> · <?= esc($h['full_name'] ?? '') ?> · <?= dmy($h['created_at']) ?></span><span><?= $h['is_active'] ? 'active' : 'replaced ' . dmy($h['replaced_at']) ?></span></div><?php endforeach ?>
            </details>
            <?php endif ?>
        </div></div>
    </div>
<?php endforeach ?>
</div>
<?php if ($wizard): ?><div class="sticky-actions"><a href="<?= site_url('settings/banks?wizard=1') ?>" class="btn btn-primary">Continue to bank & payment →</a></div><?php endif ?>
<?= $this->endSection() ?>
