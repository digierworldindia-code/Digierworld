<?= $this->extend('layouts/app') ?>
<?php
$v = static fn (string $k, $d = '') => esc((string) (old($k) ?? ($c[$k] ?? $d)));
$dis = $canEdit ? '' : 'disabled';
$contacts = '';
foreach (json_decode((string) ($c['contact_persons'] ?? '[]'), true) ?: [] as $cp) {
    $contacts .= implode(' | ', [$cp['name'], $cp['role'], $cp['phone'], $cp['email']]) . "\n";
}
$stateSelect = static function (string $name, ?string $selected) use ($states, $dis): string {
    $h = '<select class="form-select' . is_invalid($name) . '" id="' . $name . '" name="' . $name . '" ' . $dis . '><option value="">— select —</option>';
    foreach ($states as $s) {
        $h .= '<option value="' . esc($s['code']) . '"' . ($selected === $s['code'] ? ' selected' : '') . '>' . esc($s['code'] . ' — ' . $s['name']) . ($s['uses_utgst'] ? ' (UT)' : '') . '</option>';
    }

    return $h . '</select>' . field_error($name);
};
?>
<?= $this->section('content') ?>
<?php if ($wizard): ?><?= view('settings/_wizard', ['current' => 'company']) ?><?php endif ?>
<div class="alert alert-info small"><i class="bi bi-info-circle"></i> Enter your <strong>exact</strong> legal details from the GST registration certificate. Nothing here is pre-filled or guessed. Each save creates a new profile version; already-finalized invoices keep the identity printed at the time of issue.</div>
<form method="post" action="<?= site_url('settings/company') ?>">
<?= csrf_field() ?>
<?php if ($wizard): ?><input type="hidden" name="wizard" value="1"><?php endif ?>
<div class="row g-3">
<div class="col-xl-8">
    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">Legal identity</div>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label required" for="legal_name">Legal / registered name</label><input class="form-control<?= is_invalid('legal_name') ?>" id="legal_name" name="legal_name" value="<?= $v('legal_name') ?>" <?= $dis ?>><?= field_error('legal_name') ?></div>
            <div class="col-md-6"><label class="form-label" for="trade_name">Trade name (shown as brand)</label><input class="form-control" id="trade_name" name="trade_name" value="<?= $v('trade_name') ?>" <?= $dis ?>></div>
            <div class="col-md-4"><label class="form-label" for="gst_registration_type">GST registration</label>
                <select class="form-select" id="gst_registration_type" name="gst_registration_type" <?= $dis ?>>
                    <?php foreach (['regular' => 'Regular taxpayer', 'composition' => 'Composition scheme', 'unregistered' => 'Not registered'] as $k => $l): ?><option value="<?= $k ?>" <?= ($c['gst_registration_type'] ?? 'regular') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?>
                </select>
                <div class="form-text">Composition/unregistered suppliers issue bills of supply, not tax invoices — confirm with your accountant.</div></div>
            <div class="col-md-4"><label class="form-label" for="gstin">Supplier GSTIN</label><input class="form-control mono text-uppercase<?= is_invalid('gstin') ?>" id="gstin" name="gstin" value="<?= $v('gstin') ?>" maxlength="15" <?= $dis ?>><?= field_error('gstin') ?><div class="form-text">Format & check digit are validated; activeness is not verified online.</div></div>
            <div class="col-md-4"><label class="form-label" for="pan">PAN</label><input class="form-control mono text-uppercase<?= is_invalid('pan') ?>" id="pan" name="pan" value="<?= $v('pan') ?>" maxlength="10" <?= $dis ?>><?= field_error('pan') ?></div>
            <div class="col-md-6"><label class="form-label required" for="state_code">State (place of business)</label><?= $stateSelect('state_code', old('state_code') ?? ($c['state_code'] ?? null)) ?></div>
            <div class="col-md-6"><label class="form-label" for="business_category">Business category</label><input class="form-control" id="business_category" name="business_category" value="<?= $v('business_category', 'Digital marketing & IT services') ?>" <?= $dis ?>></div>
            <div class="col-md-6"><label class="form-label" for="tagline">Tagline (optional, not legal name)</label><input class="form-control" id="tagline" name="tagline" value="<?= $v('tagline') ?>" <?= $dis ?>></div>
            <div class="col-md-6"><label class="form-label" for="brand_description">Brand description (optional)</label><input class="form-control" id="brand_description" name="brand_description" value="<?= $v('brand_description') ?>" <?= $dis ?>></div>
        </div>
    </div></div>

    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">Registered address</div>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="reg_address_line1">Address line 1</label><input class="form-control" id="reg_address_line1" name="reg_address_line1" value="<?= $v('reg_address_line1') ?>" <?= $dis ?>></div>
            <div class="col-md-6"><label class="form-label" for="reg_address_line2">Address line 2</label><input class="form-control" id="reg_address_line2" name="reg_address_line2" value="<?= $v('reg_address_line2') ?>" <?= $dis ?>></div>
            <div class="col-md-4"><label class="form-label" for="reg_city">City</label><input class="form-control" id="reg_city" name="reg_city" value="<?= $v('reg_city') ?>" <?= $dis ?>></div>
            <div class="col-md-4"><label class="form-label" for="reg_district">District</label><input class="form-control" id="reg_district" name="reg_district" value="<?= $v('reg_district') ?>" <?= $dis ?>></div>
            <div class="col-md-4"><label class="form-label" for="reg_pincode">PIN code</label><input class="form-control<?= is_invalid('reg_pincode') ?>" id="reg_pincode" name="reg_pincode" value="<?= $v('reg_pincode') ?>" inputmode="numeric" maxlength="6" <?= $dis ?>><?= field_error('reg_pincode') ?></div>
        </div>
        <div class="form-check mt-3"><input class="form-check-input" type="checkbox" id="bill_address_same" name="bill_address_same" value="1" <?= ($c['bill_address_same'] ?? 1) ? 'checked' : '' ?> <?= $dis ?> data-toggle-target="#billAddr"><label class="form-check-label" for="bill_address_same">Billing address is the same as registered address</label></div>
        <div id="billAddr" class="row g-3 mt-1 <?= ($c['bill_address_same'] ?? 1) ? 'd-none' : '' ?>">
            <div class="col-md-6"><label class="form-label" for="bill_address_line1">Billing address line 1</label><input class="form-control" id="bill_address_line1" name="bill_address_line1" value="<?= $v('bill_address_line1') ?>" <?= $dis ?>></div>
            <div class="col-md-6"><label class="form-label" for="bill_address_line2">Line 2</label><input class="form-control" id="bill_address_line2" name="bill_address_line2" value="<?= $v('bill_address_line2') ?>" <?= $dis ?>></div>
            <div class="col-md-3"><label class="form-label" for="bill_city">City</label><input class="form-control" id="bill_city" name="bill_city" value="<?= $v('bill_city') ?>" <?= $dis ?>></div>
            <div class="col-md-3"><label class="form-label" for="bill_district">District</label><input class="form-control" id="bill_district" name="bill_district" value="<?= $v('bill_district') ?>" <?= $dis ?>></div>
            <div class="col-md-3"><label class="form-label" for="bill_state_code">State</label><?= $stateSelect('bill_state_code', old('bill_state_code') ?? ($c['bill_state_code'] ?? null)) ?></div>
            <div class="col-md-3"><label class="form-label" for="bill_pincode">PIN</label><input class="form-control" id="bill_pincode" name="bill_pincode" value="<?= $v('bill_pincode') ?>" maxlength="6" <?= $dis ?>></div>
        </div>
    </div></div>

    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">Contact & invoice text</div>
        <div class="row g-3">
            <div class="col-md-3"><label class="form-label" for="phone">Phone</label><input class="form-control" id="phone" name="phone" value="<?= $v('phone') ?>" <?= $dis ?>></div>
            <div class="col-md-3"><label class="form-label" for="alt_phone">Alt. phone</label><input class="form-control" id="alt_phone" name="alt_phone" value="<?= $v('alt_phone') ?>" <?= $dis ?>></div>
            <div class="col-md-3"><label class="form-label" for="email">Official email</label><input type="email" class="form-control<?= is_invalid('email') ?>" id="email" name="email" value="<?= $v('email') ?>" <?= $dis ?>><?= field_error('email') ?></div>
            <div class="col-md-3"><label class="form-label" for="website">Website</label><input class="form-control" id="website" name="website" value="<?= $v('website', 'www.digierworld.com') ?>" <?= $dis ?>></div>
            <div class="col-12"><label class="form-label" for="contact_persons">Contact persons <span class="text-muted fw-normal">(one per line: Name | Role | Phone | Email)</span></label><textarea class="form-control" id="contact_persons" name="contact_persons" rows="3" <?= $dis ?>><?= esc(old('contact_persons') ?? $contacts) ?></textarea></div>
            <div class="col-12"><label class="form-label" for="invoice_terms">Default invoice terms</label><textarea class="form-control" id="invoice_terms" name="invoice_terms" rows="3" <?= $dis ?>><?= $v('invoice_terms') ?></textarea></div>
            <div class="col-md-6"><label class="form-label" for="invoice_footer">Invoice footer / contact line</label><input class="form-control" id="invoice_footer" name="invoice_footer" value="<?= $v('invoice_footer') ?>" maxlength="500" <?= $dis ?>></div>
            <div class="col-md-6"><label class="form-label" for="legal_disclaimer">Legal disclaimer (optional)</label><input class="form-control" id="legal_disclaimer" name="legal_disclaimer" value="<?= $v('legal_disclaimer') ?>" maxlength="500" <?= $dis ?>></div>
        </div>
    </div></div>
</div>

<div class="col-xl-4">
    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">Finance defaults</div>
        <div class="mb-3"><label class="form-label" for="fy_start_month">Financial year starts in</label><select class="form-select" id="fy_start_month" name="fy_start_month" <?= $dis ?>>
            <?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= (int) ($c['fy_start_month'] ?? 4) === $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option><?php endfor ?>
        </select><div class="form-text">Indian FY: April–March.</div></div>
        <div class="mb-3"><label class="form-label" for="default_payment_terms_days">Default payment terms (days)</label><input type="number" min="0" max="365" class="form-control" id="default_payment_terms_days" name="default_payment_terms_days" value="<?= $v('default_payment_terms_days', '15') ?>" <?= $dis ?>></div>
        <div class="mb-3"><label class="form-label" for="rounding_policy">Grand total rounding</label><select class="form-select" id="rounding_policy" name="rounding_policy" <?= $dis ?>>
            <option value="nearest_rupee" <?= ($c['rounding_policy'] ?? '') !== 'none' ? 'selected' : '' ?>>Round to nearest rupee (round-off line)</option>
            <option value="none" <?= ($c['rounding_policy'] ?? '') === 'none' ? 'selected' : '' ?>>No rounding (paise)</option>
        </select><div class="form-text">Tax is computed per line, per component, rounded half-up to paise.</div></div>
        <div class="mb-3"><label class="form-label" for="default_price_mode">Default pricing</label><select class="form-select" id="default_price_mode" name="default_price_mode" <?= $dis ?>>
            <option value="exclusive" <?= ($c['default_price_mode'] ?? '') !== 'inclusive' ? 'selected' : '' ?>>Rates exclude GST</option>
            <option value="inclusive" <?= ($c['default_price_mode'] ?? '') === 'inclusive' ? 'selected' : '' ?>>Rates include GST</option>
        </select></div>
        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="einvoice_applicable" name="einvoice_applicable" value="1" <?= ! empty($c['einvoice_applicable']) ? 'checked' : '' ?> <?= $dis ?>><label class="form-check-label small" for="einvoice_applicable">Company is required to e-invoice (IRN). <span class="text-muted">No IRP integration is configured — finalization will warn that IRN must be generated on the portal.</span></label></div>
        <div class="row g-2">
            <div class="col-7"><label class="form-label" for="lut_number">LUT number (exports)</label><input class="form-control" id="lut_number" name="lut_number" value="<?= $v('lut_number') ?>" <?= $dis ?>></div>
            <div class="col-5"><label class="form-label" for="lut_valid_till">Valid till</label><input type="date" class="form-control<?= is_invalid('lut_valid_till') ?>" id="lut_valid_till" name="lut_valid_till" value="<?= $v('lut_valid_till') ?>" <?= $dis ?>><?= field_error('lut_valid_till') ?></div>
        </div>
    </div></div>
    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">Brand colours (UI)</div>
        <div class="row g-2">
            <div class="col-6"><label class="form-label" for="brand_primary">Primary</label><input type="color" class="form-control form-control-color w-100" id="brand_primary" name="brand_primary" value="<?= $v('brand_primary', '#111111') ?>" <?= $dis ?>></div>
            <div class="col-6"><label class="form-label" for="brand_accent">Accent</label><input type="color" class="form-control form-control-color w-100" id="brand_accent" name="brand_accent" value="<?= $v('brand_accent', '#B08D57') ?>" <?= $dis ?>></div>
        </div>
        <div class="form-text">Your official logo is never recoloured — these only theme the app.</div>
    </div></div>
    <div class="card"><div class="card-body small">
        <div class="card-section-title">Profile history</div>
        <?php foreach ($versions as $ver): ?><div class="d-flex justify-content-between border-bottom py-1"><span>v<?= (int) $ver['profile_version'] ?> · <?= esc($ver['full_name'] ?? 'system') ?></span><span class="text-muted"><?= dmy($ver['created_at'], true) ?></span></div><?php endforeach ?>
        <?php if ($versions === []): ?><span class="text-muted">Not saved yet.</span><?php endif ?>
    </div></div>
</div>
</div>
<?php if ($canEdit): ?><div class="sticky-actions"><button class="btn btn-dark"><?= $wizard ? 'Save & continue →' : 'Save company profile' ?></button></div><?php endif ?>
</form>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="<?= base_url('assets/js/settings.js') ?>"></script><?= $this->endSection() ?>
