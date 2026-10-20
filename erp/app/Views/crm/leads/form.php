<?= $this->extend('layouts/app') ?>
<?php
$v = static fn (string $k, $d = '') => esc((string) (old($k) ?? ($lead[$k] ?? $d)));
$svc = old('services_interested') ?? (json_decode((string) ($lead['services_interested'] ?? '[]'), true) ?: []);
$errs = flash_errors();
?>
<?= $this->section('content') ?>
<form method="post" action="<?= site_url($lead ? 'crm/leads/' . $lead['id'] : 'crm/leads') ?>" class="u-mxw-980px card"><div class="card-body">
    <?= csrf_field() ?>
    <?php if (isset($errs['duplicate'])): ?><div class="alert alert-warning small">A lead with this mobile already exists (<?= esc($errs['duplicate']) ?>). <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="allow_duplicate" value="1" id="allow_duplicate"><label class="form-check-label" for="allow_duplicate">It is a different business — save anyway</label></div></div><?php endif ?>
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label required" for="company_name">Business / company</label><input class="form-control<?= is_invalid('company_name') ?>" id="company_name" name="company_name" value="<?= $v('company_name') ?>" maxlength="200" autofocus><?= field_error('company_name') ?></div>
        <div class="col-md-6"><label class="form-label" for="contact_person">Contact person</label><input class="form-control" id="contact_person" name="contact_person" value="<?= $v('contact_person') ?>" maxlength="150"></div>
        <div class="col-md-4"><label class="form-label required" for="mobile">Mobile</label><input class="form-control<?= is_invalid('mobile') ?>" id="mobile" name="mobile" value="<?= $v('mobile') ?>" inputmode="tel" required><?= field_error('mobile') ?></div>
        <div class="col-md-4"><label class="form-label" for="alt_mobile">Alternate mobile</label><input class="form-control" id="alt_mobile" name="alt_mobile" value="<?= $v('alt_mobile') ?>" inputmode="tel"></div>
        <div class="col-md-4"><label class="form-label" for="email">Email</label><input type="email" class="form-control<?= is_invalid('email') ?>" id="email" name="email" value="<?= $v('email') ?>"><?= field_error('email') ?></div>
        <div class="col-md-6"><label class="form-label" for="address">Address</label><input class="form-control" id="address" name="address" value="<?= $v('address') ?>" maxlength="300"></div>
        <div class="col-md-2"><label class="form-label" for="pincode">PIN</label><input class="form-control<?= is_invalid('pincode') ?>" id="pincode" name="pincode" value="<?= $v('pincode') ?>" inputmode="numeric" maxlength="6"><?= field_error('pincode') ?></div>
        <div class="col-md-2"><label class="form-label" for="area">Area</label><input class="form-control" id="area" name="area" value="<?= $v('area') ?>"></div>
        <div class="col-md-2"><label class="form-label" for="city">City</label><input class="form-control" id="city" name="city" value="<?= $v('city') ?>"></div>
        <div class="col-md-4"><label class="form-label" for="industry">Business category / industry</label><input class="form-control" id="industry" name="industry" value="<?= $v('industry') ?>" list="industries"><datalist id="industries"><?php foreach (['Healthcare', 'Education', 'Real estate', 'Retail', 'Restaurant / food', 'Manufacturing', 'Hospitality', 'Automobile', 'Professional services', 'E-commerce'] as $i): ?><option value="<?= $i ?>"><?php endforeach ?></datalist></div>
        <div class="col-md-8"><label class="form-label" for="svc">Digital services required</label>
            <select class="form-select" id="svc" name="services_interested[]" multiple size="4"><?php foreach (array_unique(array_merge($services, $svc)) as $s): ?><option value="<?= esc($s) ?>" <?= in_array($s, $svc, true) ? 'selected' : '' ?>><?= esc($s) ?></option><?php endforeach ?></select>
            <div class="form-text">Ctrl/Cmd-click to choose several.</div></div>
        <div class="col-md-3"><label class="form-label" for="source_id">Lead source</label><select class="form-select" id="source_id" name="source_id"><option value="">—</option><?php foreach ($sources as $s): ?><option value="<?= $s['id'] ?>" <?= (string) (old('source_id') ?? ($lead['source_id'] ?? '')) === (string) $s['id'] ? 'selected' : '' ?>><?= esc($s['name']) ?></option><?php endforeach ?></select></div>
        <div class="col-md-3"><label class="form-label" for="campaign">Campaign</label><input class="form-control" id="campaign" name="campaign" value="<?= $v('campaign') ?>"></div>
        <div class="col-md-3"><label class="form-label" for="priority">Priority</label><select class="form-select" id="priority" name="priority"><?php foreach (['medium', 'low', 'high', 'hot'] as $p): ?><option value="<?= $p ?>" <?= (old('priority') ?? ($lead['priority'] ?? 'medium')) === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option><?php endforeach ?></select></div>
        <div class="col-md-3"><label class="form-label" for="expected_value">Expected deal (₹)</label><input class="form-control" id="expected_value" name="expected_value" value="<?= $v('expected_value') ?>" inputmode="decimal"></div>
        <?php if (! $lead): ?>
        <div class="col-md-4"><label class="form-label" for="assigned_to">Assign to</label><select class="form-select" id="assigned_to" name="assigned_to" <?= $canAssign ? '' : 'disabled' ?>><?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>" <?= (int) $u['id'] === (int) current_user_id() ? 'selected' : '' ?>><?= esc($u['name']) ?></option><?php endforeach ?></select><?= $canAssign ? '' : '<div class="form-text">Leads you create are assigned to you.</div>' ?></div>
        <div class="col-md-4"><label class="form-label" for="next_followup_at">First call at</label><input type="datetime-local" class="form-control" id="next_followup_at" name="next_followup_at" value="<?= $v('next_followup_at') ?>"></div>
        <?php endif ?>
        <div class="col-md-4"><label class="form-label" for="consent_status">Call consent</label><select class="form-select" id="consent_status" name="consent_status"><?php foreach (['unknown' => 'Not yet asked', 'consented' => 'Agreed to be contacted', 'withdrawn' => 'Withdrawn — do not call'] as $k => $l): ?><option value="<?= $k ?>" <?= (old('consent_status') ?? ($lead['consent_status'] ?? 'unknown')) === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></div>
        <div class="col-12"><label class="form-label" for="remarks">Remarks</label><textarea class="form-control" id="remarks" name="remarks" rows="2"><?= $v('remarks') ?></textarea></div>
    </div>
</div>
<div class="card-footer text-end"><a class="btn btn-outline-secondary" href="<?= site_url($lead ? 'crm/leads/' . $lead['id'] : 'crm/leads') ?>">Cancel</a> <button class="btn btn-primary" type="submit">Save lead</button></div>
</form>
<?= $this->endSection() ?>
