<?= $this->extend('layouts/app') ?>
<?php $wizard = (bool) service('request')->getGet('wizard'); ?>
<?= $this->section('content') ?>
<?php if ($wizard): ?><?= view('settings/_wizard', ['current' => 'templates']) ?><?php endif ?>
<p class="small text-muted">Choose the default layout and adjust it. Each save creates a new template version; invoices that are already finalized keep the exact layout they were issued with. The logo is always printed exactly as uploaded.</p>
<div class="row g-3">
<?php foreach ($rows as $t): $s = $t['settings']; $chk = static fn (string $k, bool $d = true) => ($s[$k] ?? $d) ? 'checked' : ''; ?>
<div class="col-xl-6">
<form method="post" action="<?= site_url('settings/templates/' . $t['id'] . ($wizard ? '?wizard=1' : '')) ?>" class="card h-100 <?= (int) $t['id'] === $default ? 'border-primary' : '' ?>">
    <?= csrf_field() ?>
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-2">
            <div><div class="fw-semibold"><?= esc($t['name']) ?> <?php if ((int) $t['id'] === $default): ?><span class="badge badge-neutral">Default</span><?php endif ?></div>
                <div class="small text-muted"><?= esc(ucfirst($t['layout'])) ?> layout · version <?= (int) $t['version'] ?> · used on <?= (int) $t['used'] ?> issued document(s)</div></div>
            <div class="btn-group btn-group-sm"><a class="btn btn-outline-secondary" href="<?= site_url('settings/templates/' . $t['id'] . '/preview') ?>" target="_blank" rel="noopener"><i class="bi bi-eye"></i> Preview</a><a class="btn btn-outline-secondary" href="<?= site_url('settings/templates/' . $t['id'] . '/preview?gst=igst&signed=1') ?>" target="_blank" rel="noopener" title="Inter-state sample with signature">IGST</a></div>
        </div>
        <div class="row g-2 small">
            <div class="col-4"><label class="form-label" for="ac<?= $t['id'] ?>">Accent</label><input type="color" class="form-control form-control-color w-100" id="ac<?= $t['id'] ?>" name="accent" value="<?= esc($s['accent'] ?? '#222222') ?>"></div>
            <div class="col-4"><label class="form-label" for="fs<?= $t['id'] ?>">Font size (pt)</label><input type="number" step="0.5" min="8" max="11" class="form-control form-control-sm" id="fs<?= $t['id'] ?>" name="font_size" value="<?= esc((string) ($s['font_size'] ?? 9.5)) ?>"></div>
            <div class="col-4"><label class="form-label" for="mm<?= $t['id'] ?>">Margin (mm)</label><input type="number" min="8" max="20" class="form-control form-control-sm" id="mm<?= $t['id'] ?>" name="margin_mm" value="<?= (int) ($s['margin_mm'] ?? 12) ?>"></div>
            <div class="col-6"><label class="form-label" for="pp<?= $t['id'] ?>">Paper</label><select class="form-select form-select-sm" id="pp<?= $t['id'] ?>" name="paper"><option value="A4" <?= $t['paper'] === 'A4' ? 'selected' : '' ?>>A4</option><option value="A5" <?= $t['paper'] === 'A5' ? 'selected' : '' ?>>A5</option></select></div>
            <div class="col-6"><label class="form-label" for="lp<?= $t['id'] ?>">Logo position</label><select class="form-select form-select-sm" id="lp<?= $t['id'] ?>" name="logo_position"><?php foreach ($positions as $k => $l): ?><option value="<?= $k ?>" <?= ($s['logo_position'] ?? 'top_left') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></div>
            <div class="col-12"><label class="form-label" for="fn<?= $t['id'] ?>">Footer note</label><input class="form-control form-control-sm" id="fn<?= $t['id'] ?>" name="footer_note" maxlength="200" value="<?= esc((string) ($s['footer_note'] ?? '')) ?>"></div>
            <div class="col-12 d-flex flex-wrap gap-3 mt-1">
                <?php foreach (['show_logo' => 'Logo', 'show_bank' => 'Bank details', 'show_upi_qr' => 'UPI QR', 'show_tax_summary' => 'Tax summary', 'show_amount_words' => 'Amount in words', 'show_terms' => 'Terms', 'show_signature' => 'Signature', 'show_stamp' => 'Stamp'] as $k => $l): ?>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="<?= $k ?>" value="1" id="<?= $k . $t['id'] ?>" <?= $chk($k) ?>><label class="form-check-label" for="<?= $k . $t['id'] ?>"><?= $l ?></label></div>
                <?php endforeach ?>
            </div>
            <div class="col-12 d-flex flex-wrap gap-3 border-top pt-2 mt-1">
                <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="act<?= $t['id'] ?>" <?= (int) $t['is_active'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="act<?= $t['id'] ?>">Available when creating documents</label></div>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="make_default" value="1" id="def<?= $t['id'] ?>" <?= (int) $t['id'] === $default ? 'checked disabled' : '' ?>><label class="form-check-label" for="def<?= $t['id'] ?>">Make default</label></div>
            </div>
        </div>
        <div class="small text-muted mt-2">Signature and stamp appear only when the signature policy allows the person finalizing, and they choose to apply it.</div>
    </div>
    <div class="card-footer text-end"><button class="btn btn-primary btn-sm" type="submit">Save as new version</button></div>
</form>
</div>
<?php endforeach ?>
</div>
<?php if ($wizard): ?><div class="sticky-actions"><a href="<?= site_url('settings/signature-policy?wizard=1') ?>" class="btn btn-primary">Continue to signature policy →</a></div><?php endif ?>
<?= $this->endSection() ?>
