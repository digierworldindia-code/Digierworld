<?= $this->extend('layouts/app') ?>
<?php
$v = static fn (string $k, $d = '') => esc((string) (old($k) ?? ($c[$k] ?? $d)));
$chk = static fn (string $k, bool $def = false) => (old($k) !== null ? (bool) old($k) : (isset($c[$k]) ? (bool) $c[$k] : $def)) ? 'checked' : '';
$stateOpts = static function (?string $sel) use ($states): string {
    $h = '<option value="">— select —</option>';
    foreach ($states as $s) {
        $h .= '<option value="' . $s['code'] . '"' . ($sel === $s['code'] ? ' selected' : '') . '>' . esc($s['code'] . ' — ' . $s['name']) . '</option>';
    }

    return $h;
};
$errs = flash_errors();
?>
<?= $this->section('content') ?>
<form method="post" action="<?= site_url($c ? 'contacts/' . $c['id'] : 'contacts') ?>" data-contact-form data-exclude="<?= (int) ($c['id'] ?? 0) ?>">
<?= csrf_field() ?>
<?php if (isset($errs['duplicate'])): ?>
<div class="alert alert-warning"><i class="bi bi-files"></i> Possible duplicate: <strong><?= esc($errs['duplicate']) ?></strong>
    <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="allow_duplicate" value="1" id="allow_duplicate"><label class="form-check-label" for="allow_duplicate">This is a different party — save anyway</label></div></div>
<?php endif ?>
<div class="alert alert-warning d-none small" data-dupe-alert></div>
<div class="row g-3">
<div class="col-xl-8">
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex flex-wrap gap-3 mb-3">
            <div class="form-check"><input class="form-check-input" type="checkbox" name="is_customer" value="1" id="is_customer" <?= $chk('is_customer', true) ?>><label class="form-check-label" for="is_customer">Customer</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="is_vendor" value="1" id="is_vendor" <?= $chk('is_vendor') ?>><label class="form-check-label" for="is_vendor">Vendor</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="is_prospect" value="1" id="is_prospect" <?= $chk('is_prospect') ?>><label class="form-check-label" for="is_prospect">Prospect</label></div>
            <div class="ms-auto"><select class="form-select form-select-sm" name="entity_kind" aria-label="Entity kind"><option value="company">Company / firm</option><option value="individual" <?= ($c['entity_kind'] ?? old('entity_kind')) === 'individual' ? 'selected' : '' ?>>Individual</option></select></div>
        </div>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label required" for="name">Name</label><input class="form-control<?= is_invalid('name') ?>" id="name" name="name" value="<?= $v('name') ?>" required><?= field_error('name') ?></div>
            <div class="col-md-6"><label class="form-label" for="legal_name">Legal name (for invoices, if different)</label><input class="form-control" id="legal_name" name="legal_name" value="<?= $v('legal_name') ?>"></div>
            <div class="col-md-4"><label class="form-label" for="contact_person">Contact person</label><input class="form-control" id="contact_person" name="contact_person" value="<?= $v('contact_person') ?>"></div>
            <div class="col-md-4"><label class="form-label" for="phone">Phone</label><input class="form-control<?= is_invalid('phone') ?>" id="phone" name="phone" value="<?= $v('phone') ?>" inputmode="tel" data-dupe-check><?= field_error('phone') ?></div>
            <div class="col-md-4"><label class="form-label" for="alt_phone">Alternate phone</label><input class="form-control" id="alt_phone" name="alt_phone" value="<?= $v('alt_phone') ?>" inputmode="tel"></div>
            <div class="col-md-4"><label class="form-label" for="whatsapp">WhatsApp</label><input class="form-control" id="whatsapp" name="whatsapp" value="<?= $v('whatsapp') ?>" inputmode="tel"></div>
            <div class="col-md-8"><label class="form-label" for="email">Email</label><input type="email" class="form-control<?= is_invalid('email') ?>" id="email" name="email" value="<?= $v('email') ?>"><?= field_error('email') ?></div>
        </div>
    </div></div>

    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">GST & tax state</div>
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label" for="gst_registration_type">GST registration</label><select class="form-select" id="gst_registration_type" name="gst_registration_type">
                <?php foreach (['unregistered' => 'Unregistered (B2C)', 'registered' => 'Registered (B2B)', 'composition' => 'Composition dealer', 'consumer' => 'Consumer', 'sez' => 'SEZ unit / developer', 'overseas' => 'Overseas (export)', 'uin' => 'UIN holder (embassy/UN)'] as $k => $l): ?><option value="<?= $k ?>" <?= ((string) (old('gst_registration_type') ?? ($c['gst_registration_type'] ?? 'unregistered'))) === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></div>
            <div class="col-md-4"><label class="form-label" for="gstin">GSTIN</label><input class="form-control mono text-uppercase<?= is_invalid('gstin') ?>" id="gstin" name="gstin" value="<?= $v('gstin') ?>" maxlength="15" data-gstin-check data-dupe-check><?= field_error('gstin') ?><div class="form-text" data-gstin-result></div></div>
            <div class="col-md-4"><label class="form-label" for="pan">PAN</label><input class="form-control mono text-uppercase<?= is_invalid('pan') ?>" id="pan" name="pan" value="<?= $v('pan') ?>" maxlength="10"><?= field_error('pan') ?></div>
            <div class="col-md-6"><label class="form-label" for="state_code">State (decides default place of supply)</label><select class="form-select<?= is_invalid('state_code') ?>" id="state_code" name="state_code"><?= $stateOpts(old('state_code') ?? ($c['state_code'] ?? null)) ?></select><?= field_error('state_code') ?>
                <div class="form-text">Auto-suggested from GSTIN. Choosing a different state requires a reason (audited).</div></div>
            <div class="col-md-6"><label class="form-label" for="state_override_reason">Reason if state differs from GSTIN</label><input class="form-control" id="state_override_reason" name="state_override_reason" value="<?= esc((string) old('state_override_reason')) ?>"></div>
        </div>
    </div></div>

    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">Addresses</div>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="billing_line1">Billing address line 1</label><input class="form-control" id="billing_line1" name="billing_line1" value="<?= esc((string) (old('billing_line1') ?? ($billing['line1'] ?? ''))) ?>"></div>
            <div class="col-md-6"><label class="form-label" for="billing_line2">Line 2</label><input class="form-control" id="billing_line2" name="billing_line2" value="<?= esc((string) (old('billing_line2') ?? ($billing['line2'] ?? ''))) ?>"></div>
            <div class="col-md-4"><label class="form-label" for="city">City</label><input class="form-control" id="city" name="city" value="<?= $v('city') ?>"></div>
            <div class="col-md-4"><label class="form-label" for="district">District</label><input class="form-control" id="district" name="district" value="<?= $v('district') ?>"></div>
            <div class="col-md-4"><label class="form-label" for="pincode">PIN code</label><input class="form-control<?= is_invalid('pincode') ?>" id="pincode" name="pincode" value="<?= $v('pincode') ?>" inputmode="numeric" maxlength="6"><?= field_error('pincode') ?></div>
            <div class="col-md-6"><label class="form-label" for="service_line1">Service / site address (optional)</label><input class="form-control" id="service_line1" name="service_line1" value="<?= esc((string) (old('service_line1') ?? ($service['line1'] ?? ''))) ?>"></div>
            <div class="col-md-3"><label class="form-label" for="service_city">Service city</label><input class="form-control" id="service_city" name="service_city" value="<?= esc((string) (old('service_city') ?? ($service['city'] ?? ''))) ?>"></div>
            <div class="col-md-3"><label class="form-label" for="service_state_code">Service state</label><select class="form-select" id="service_state_code" name="service_state_code"><?= $stateOpts(old('service_state_code') ?? ($service['state_code'] ?? null)) ?></select></div>
        </div>
    </div></div>

    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">Additional contact people</div>
        <?php $ppl = $people ?: [[]]; foreach (array_merge($ppl, [[]]) as $i => $p): ?>
        <div class="row g-2 mb-2">
            <div class="col-md-3"><input class="form-control form-control-sm" name="people[<?= $i ?>][name]" value="<?= esc($p['name'] ?? '') ?>" placeholder="Name" aria-label="Person name"></div>
            <div class="col-md-3"><input class="form-control form-control-sm" name="people[<?= $i ?>][designation]" value="<?= esc($p['designation'] ?? '') ?>" placeholder="Designation" aria-label="Designation"></div>
            <div class="col-md-2"><input class="form-control form-control-sm" name="people[<?= $i ?>][phone]" value="<?= esc($p['phone'] ?? '') ?>" placeholder="Phone" aria-label="Phone"></div>
            <div class="col-md-3"><input class="form-control form-control-sm" name="people[<?= $i ?>][email]" value="<?= esc($p['email'] ?? '') ?>" placeholder="Email" aria-label="Email"></div>
            <div class="col-md-1 form-check pt-1"><input class="form-check-input" type="checkbox" name="people[<?= $i ?>][is_primary]" value="1" <?= ! empty($p['is_primary']) ? 'checked' : '' ?> title="Primary" aria-label="Primary"></div>
        </div>
        <?php endforeach ?>
    </div></div>
</div>
<div class="col-xl-4">
    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">Business</div>
        <div class="mb-3"><label class="form-label" for="industry">Industry / business field</label><input class="form-control" id="industry" name="industry" value="<?= $v('industry') ?>"></div>
        <div class="mb-3"><label class="form-label" for="source">Source</label><input class="form-control" id="source" name="source" value="<?= $v('source') ?>" list="sources"><datalist id="sources"><?php foreach ($sources as $s): ?><option value="<?= esc($s) ?>"><?php endforeach ?></datalist></div>
        <div class="mb-3"><label class="form-label" for="category">Category</label><input class="form-control" id="category" name="category" value="<?= $v('category') ?>"></div>
        <div class="mb-3"><label class="form-label" for="tags">Tags</label><input class="form-control" id="tags" name="tags" value="<?= $v('tags') ?>" placeholder="comma separated"></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="dnd" value="1" id="dnd" <?= $chk('dnd') ?>><label class="form-check-label small" for="dnd">Do not call / market (consent withdrawn)</label></div>
    </div></div>
    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">Credit terms</div>
        <div class="row g-2">
            <div class="col-6"><label class="form-label" for="credit_days">Credit days</label><input type="number" min="0" max="365" class="form-control" id="credit_days" name="credit_days" value="<?= $v('credit_days') ?>" placeholder="company default"></div>
            <div class="col-6"><label class="form-label" for="credit_limit">Credit limit ₹</label><input class="form-control" id="credit_limit" name="credit_limit" value="<?= $v('credit_limit') ?>" inputmode="decimal"></div>
            <?php if (! $c): ?>
            <div class="col-7"><label class="form-label" for="opening_balance">Opening balance ₹</label><input class="form-control" id="opening_balance" name="opening_balance" value="<?= $v('opening_balance', '0') ?>" inputmode="decimal"></div>
            <div class="col-5"><label class="form-label" for="opening_balance_type">Type</label><select class="form-select" id="opening_balance_type" name="opening_balance_type"><option value="dr">Receivable (Dr)</option><option value="cr">Payable/advance (Cr)</option></select></div>
            <?php endif ?>
        </div>
    </div></div>
    <div class="card"><div class="card-body"><label class="form-label" for="notes">Notes</label><textarea class="form-control" id="notes" name="notes" rows="4"><?= $v('notes') ?></textarea></div></div>
</div>
</div>
<div class="sticky-actions"><a href="<?= site_url(! can('contacts.view_all') ? '/' : ($c ? 'contacts/' . $c['id'] : 'contacts')) ?>" class="btn btn-outline-secondary">Cancel</a><button class="btn btn-dark">Save contact</button></div>
</form>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="<?= base_url('assets/js/contacts.js') ?>"></script><?= $this->endSection() ?>
