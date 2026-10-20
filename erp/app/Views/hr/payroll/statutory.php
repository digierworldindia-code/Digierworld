<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="u-mxw-980px alert alert-warning small">No statutory rate is assumed. Applicability (establishment size, state, wage ceilings, slabs) differs by business and changes over time. Enter only what your accountant/HR has confirmed in writing and tick “reviewed”. Disabled items are simply not deducted.</div>
<div class="u-mxw-1100px row g-3">
<?php foreach ($items as $code => $s): $cfg = json_decode((string) $s['config'], true) ?: []; ?>
<div class="col-md-6"><form method="post" action="<?= site_url('hr/payroll/statutory/' . $code) ?>" class="card h-100"><div class="card-body small"><?= csrf_field() ?>
    <div class="d-flex justify-content-between"><div class="card-section-title"><?= esc($s['label']) ?> (<?= $code ?>)</div><?= $s['enabled'] ? status_badge('active') : '<span class="badge badge-neutral">off</span>' ?></div>
    <div class="row g-2">
        <?php if (in_array($code, ['PF', 'ESI'], true)): ?>
        <div class="col-4"><label class="form-label" for="<?= $code ?>ee">Employee %</label><input class="form-control form-control-sm" id="<?= $code ?>ee" name="employee_rate" value="<?= esc((string) $s['employee_rate']) ?>" inputmode="decimal"></div>
        <div class="col-4"><label class="form-label" for="<?= $code ?>er">Employer %</label><input class="form-control form-control-sm" id="<?= $code ?>er" name="employer_rate" value="<?= esc((string) $s['employer_rate']) ?>" inputmode="decimal"></div>
        <div class="col-4"><label class="form-label" for="<?= $code ?>wc">Wage ceiling ₹</label><input class="form-control form-control-sm" id="<?= $code ?>wc" name="wage_ceiling" value="<?= esc((string) $s['wage_ceiling']) ?>" inputmode="decimal"></div>
        <?php elseif (in_array($code, ['PT', 'LWF'], true)): ?>
        <div class="col-6"><label class="form-label" for="<?= $code ?>fx">Monthly amount ₹</label><input class="form-control form-control-sm" id="<?= $code ?>fx" name="fixed_amount" value="<?= esc((string) $s['fixed_amount']) ?>" inputmode="decimal"></div>
        <div class="col-6 form-text align-self-end">Slab-based state rules are not automated; enter the confirmed monthly amount.</div>
        <?php else: ?>
        <div class="col-12 form-text">TDS is deducted per employee from the “TDS ₹/month” on their salary structure, as computed by your accountant.</div>
        <?php endif ?>
        <div class="col-12"><input class="form-control form-control-sm" name="notes" value="<?= esc((string) $s['notes']) ?>" placeholder="Notes / basis (e.g. confirmed by CA on …)" aria-label="Notes"></div>
        <div class="col-12 d-flex gap-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="enabled" value="1" id="<?= $code ?>en" <?= $s['enabled'] ? 'checked' : '' ?>><label class="form-check-label" for="<?= $code ?>en">Enabled</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="reviewed" value="1" id="<?= $code ?>rv"><label class="form-check-label" for="<?= $code ?>rv">Reviewed by HR/accountant</label></div></div>
    </div>
    <?= $s['reviewed_at'] ? '<div class="text-muted mt-1">Last reviewed ' . dmy($s['reviewed_at'], true) . '</div>' : '' ?>
    <button class="btn btn-primary btn-sm mt-2">Save <?= $code ?></button>
</div></form></div>
<?php endforeach ?>
</div>
<?= $this->endSection() ?>
