<?= $this->extend('layouts/app') ?>
<?php $v = static fn (string $k, $d = '') => esc((string) (old($k) ?? ($s[$k] ?? $d))); ?>
<?= $this->section('content') ?>
<form method="post" action="<?= site_url($s ? 'services/' . $s['id'] : 'services') ?>">
<?= csrf_field() ?>
<div class="row g-3"><div class="col-lg-8"><div class="card"><div class="card-body"><div class="row g-3">
    <div class="col-md-3"><label class="form-label required" for="code">Code</label><input class="form-control mono text-uppercase<?= is_invalid('code') ?>" id="code" name="code" value="<?= $v('code') ?>" maxlength="30"><?= field_error('code') ?></div>
    <div class="col-md-9"><label class="form-label required" for="name">Service name</label><input class="form-control<?= is_invalid('name') ?>" id="name" name="name" value="<?= $v('name') ?>"><?= field_error('name') ?></div>
    <div class="col-12"><label class="form-label" for="description">Default description (printed on invoice, editable per line)</label><textarea class="form-control" id="description" name="description" rows="3"><?= $v('description') ?></textarea></div>
    <div class="col-md-4"><label class="form-label" for="category">Category</label><input class="form-control" id="category" name="category" value="<?= $v('category') ?>"></div>
    <div class="col-md-4"><label class="form-label" for="unit">Unit</label><input class="form-control" id="unit" name="unit" value="<?= $v('unit', 'Service') ?>"></div>
    <div class="col-md-4"><label class="form-label" for="default_rate">Default rate ₹</label><input class="form-control" id="default_rate" name="default_rate" value="<?= $v('default_rate', '0.00') ?>" inputmode="decimal"></div>
    <div class="col-md-4"><label class="form-label" for="sac_code">SAC / HSN</label><input class="form-control mono<?= is_invalid('sac_code') ?>" id="sac_code" name="sac_code" value="<?= $v('sac_code') ?>" inputmode="numeric" maxlength="8"><?= field_error('sac_code') ?></div>
    <div class="col-md-4"><label class="form-label" for="default_tax_rate_id">Default GST rate</label><select class="form-select" id="default_tax_rate_id" name="default_tax_rate_id"><option value="">— choose per invoice —</option>
        <?php foreach ($rates as $r): ?><option value="<?= $r['id'] ?>" <?= (string) (old('default_tax_rate_id') ?? ($s['default_tax_rate_id'] ?? '')) === (string) $r['id'] ? 'selected' : '' ?>><?= esc($r['name']) ?><?= $r['review_status'] === 'suggested' ? ' (unreviewed)' : '' ?></option><?php endforeach ?></select></div>
    <div class="col-md-4"><label class="form-label" for="default_tax_treatment">Default treatment</label><select class="form-select" id="default_tax_treatment" name="default_tax_treatment">
        <?php foreach ($treatments as $t): ?><option value="<?= esc($t['code']) ?>" <?= (string) (old('default_tax_treatment') ?? ($s['default_tax_treatment'] ?? 'regular')) === $t['code'] ? 'selected' : '' ?>><?= esc($t['label']) ?></option><?php endforeach ?></select></div>
</div></div></div></div>
<div class="col-lg-4"><div class="card"><div class="card-body">
    <div class="mb-3"><label class="form-label" for="pos_rule">Place-of-supply rule</label><select class="form-select" id="pos_rule" name="pos_rule">
        <option value="default">Default rule (recipient location)</option>
        <option value="review_required" <?= ($s['pos_rule'] ?? '') === 'review_required' ? 'selected' : '' ?>>Special rule — require tax review on each invoice</option></select>
        <div class="form-text">Use “review” for services linked to immovable property, events, training at a location, etc., where POS may not be the recipient's location.</div></div>
    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="is_recurring_type" name="is_recurring_type" value="1" <?= ! empty($s['is_recurring_type']) ? 'checked' : '' ?>><label class="form-check-label" for="is_recurring_type">Usually billed monthly (retainer)</label></div>
    <?php if ($s): ?><div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= $s['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="is_active">Active</label></div><?php endif ?>
    <?php if ($canReview): ?>
    <div class="mt-3"><label class="form-label" for="review_status">Tax review</label><select class="form-select" id="review_status" name="review_status"><option value="suggested">Suggested (not confirmed)</option><option value="accountant_reviewed" <?= ($s['review_status'] ?? '') === 'accountant_reviewed' ? 'selected' : '' ?>>Accountant reviewed SAC & rate</option></select></div>
    <?php else: ?><div class="small text-muted mt-3">Changing SAC or rate resets the accountant-review status.</div><?php endif ?>
</div></div></div></div>
<div class="sticky-actions"><a href="<?= site_url('services') ?>" class="btn btn-outline-secondary">Cancel</a><button class="btn btn-primary">Save service</button></div>
</form>
<?= $this->endSection() ?>
