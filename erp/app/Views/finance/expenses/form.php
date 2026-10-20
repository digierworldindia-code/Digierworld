<?= $this->extend('layouts/app') ?>
<?php
$v    = static fn (string $k, $d = '') => esc((string) (old($k) ?? ($e[$k] ?? ($prefill[$k] ?? $d))));
$sel  = static fn (string $k, $val) => (string) (old($k) ?? ($e[$k] ?? ($prefill[$k] ?? ''))) === (string) $val ? 'selected' : '';
$errs = flash_errors();
?>
<?= $this->section('content') ?>
<form method="post" action="<?= site_url($e ? 'finance/expenses/' . $e['id'] : 'finance/expenses') ?>" enctype="multipart/form-data" data-expense-form>
<?= csrf_field() ?>
<input type="hidden" name="recurring_expense_id" value="<?= $v('recurring_expense_id') ?>">
<script type="application/json" id="expense-data"><?= json_encode(['lookupUrl' => site_url('contacts/lookup'), 'companyState' => $companyState, 'categories' => array_column($categories, 'default_itc_rule_id', 'id'),
    'rules' => array_map(static fn ($r) => ['id' => (int) $r['id'], 'eligible' => (int) $r['eligible'], 'pct' => $r['eligible_percent']], $itcRules)], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php if (isset($errs['duplicate'])): ?>
<div class="alert alert-warning"><i class="bi bi-files"></i> Possible duplicate of <strong><?= esc($errs['duplicate']) ?></strong>.
    <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="allow_duplicate" value="1" id="allow_duplicate"><label class="form-check-label" for="allow_duplicate">Not a duplicate — save anyway</label></div></div>
<?php endif ?>
<div class="row g-3">
<div class="col-xl-8">
    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">Vendor &amp; bill</div>
        <div class="row g-3">
            <div class="col-md-6 position-relative">
                <label class="form-label" for="vendor_search">Vendor</label>
                <input type="hidden" name="vendor_contact_id" value="<?= $v('vendor_contact_id') ?>" data-vendor-id>
                <div class="dropdown"><input class="form-control" id="vendor_search" autocomplete="off" placeholder="Type vendor name or GSTIN" value="<?= esc($vendor['name'] ?? '') ?>" data-vendor-input>
                    <div class="dropdown-menu w-100" data-vendor-results></div></div>
                <div class="form-text">No vendor master yet? <a href="<?= site_url('contacts/new') ?>" target="_blank" rel="noopener">Add vendor</a>. Leave blank for small cash spends.</div>
            </div>
            <div class="col-md-6"><label class="form-label" for="vendor_gstin">Vendor GSTIN</label><input class="form-control mono<?= is_invalid('vendor_gstin') ?>" id="vendor_gstin" name="vendor_gstin" maxlength="15" value="<?= $v('vendor_gstin', $vendor['gstin'] ?? '') ?>" data-vendor-gstin><?= field_error('vendor_gstin') ?><div class="form-text">Without a vendor GSTIN no input tax credit is taken.</div></div>
            <div class="col-md-4"><label class="form-label" for="vendor_invoice_no">Vendor invoice no.</label><input class="form-control" id="vendor_invoice_no" name="vendor_invoice_no" maxlength="50" value="<?= $v('vendor_invoice_no') ?>"></div>
            <div class="col-md-4"><label class="form-label" for="vendor_invoice_date">Invoice date</label><input type="date" class="form-control" id="vendor_invoice_date" name="vendor_invoice_date" value="<?= $v('vendor_invoice_date') ?>"></div>
            <div class="col-md-4"><label class="form-label required" for="expense_date">Expense date</label><input type="date" class="form-control<?= is_invalid('expense_date') ?>" id="expense_date" name="expense_date" value="<?= $v('expense_date', date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>" required><?= field_error('expense_date') ?></div>
            <div class="col-md-6"><label class="form-label required" for="category_id">Category</label><select class="form-select<?= is_invalid('category_id') ?>" id="category_id" name="category_id" required data-category><option value="">— choose —</option><?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>" <?= $sel('category_id', $c['id']) ?>><?= esc($c['name']) ?></option><?php endforeach ?></select><?= field_error('category_id') ?></div>
            <div class="col-md-6"><label class="form-label" for="sac_hsn">SAC / HSN</label><input class="form-control" id="sac_hsn" name="sac_hsn" maxlength="8" inputmode="numeric" value="<?= $v('sac_hsn') ?>"></div>
            <div class="col-12"><label class="form-label required" for="description">Description</label><input class="form-control<?= is_invalid('description') ?>" id="description" name="description" maxlength="500" value="<?= $v('description') ?>" required><?= field_error('description') ?></div>
        </div>
    </div></div>
    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">Amount &amp; GST charged by the vendor</div>
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label required" for="taxable_amount">Amount before GST (₹)</label><input class="form-control<?= is_invalid('taxable_amount') ?>" id="taxable_amount" name="taxable_amount" inputmode="decimal" value="<?= $v('taxable_amount') ?>" required data-taxable><?= field_error('taxable_amount') ?></div>
            <div class="col-md-4"><label class="form-label" for="gst_type">GST on the bill</label><select class="form-select" id="gst_type" name="gst_type" data-gst-type>
                <option value="none" <?= $sel('gst_type', 'none') ?>>No GST on bill</option><option value="cgst_sgst" <?= $sel('gst_type', 'cgst_sgst') ?>>CGST + SGST</option><option value="cgst_utgst" <?= $sel('gst_type', 'cgst_utgst') ?>>CGST + UTGST</option><option value="igst" <?= $sel('gst_type', 'igst') ?>>IGST</option></select></div>
            <div class="col-md-4"><label class="form-label" for="gst_rate">GST rate %</label><select class="form-select<?= is_invalid('gst_rate') ?>" id="gst_rate" name="gst_rate" data-gst-rate><option value="0">—</option><?php foreach ($rates as $r): if ((float) $r <= 0) { continue; } ?><option value="<?= esc($r) ?>" <?= (float) (old('gst_rate') ?? ($e['gst_rate'] ?? 0)) === (float) $r ? 'selected' : '' ?>><?= esc(\App\Libraries\Dec::rateLabel($r)) ?>%</option><?php endforeach ?></select><?= field_error('gst_rate') ?></div>
            <?php if ($canSetItc): ?>
            <div class="col-md-8"><label class="form-label" for="itc_rule_id">Input tax credit rule (accountant)</label><select class="form-select" id="itc_rule_id" name="itc_rule_id" data-itc-rule><option value="">Category default</option><?php foreach ($itcRules as $r): ?><option value="<?= $r['id'] ?>" <?= $sel('itc_rule_id', $r['id']) ?>><?= esc($r['name']) ?><?= $r['review_status'] === 'suggested' ? ' (not yet reviewed)' : '' ?></option><?php endforeach ?></select></div>
            <?php endif ?>
        </div>
        <div class="small mt-2 text-muted" data-gst-hint></div>
    </div></div>
    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">Who paid?</div>
        <div class="row g-3">
            <div class="col-md-6"><select class="form-select" name="paid_through" aria-label="Paid through" data-paid-through>
                <option value="unpaid" <?= $sel('paid_through', 'unpaid') ?>>Not paid yet (company will pay vendor)</option>
                <option value="company" <?= $sel('paid_through', 'company') ?>>Company paid (record payment after approval)</option>
                <option value="employee_reimbursable" <?= $sel('paid_through', 'employee_reimbursable') ?>>Employee paid — reimburse</option></select></div>
            <div class="col-md-6 <?= (old('paid_through') ?? ($e['paid_through'] ?? '')) === 'employee_reimbursable' ? '' : 'd-none' ?>" data-employee-wrap><select class="form-select" name="paid_by_employee_id" aria-label="Employee"><option value="">— employee —</option><?php foreach ($employees as $em): ?><option value="<?= $em['id'] ?>" <?= $sel('paid_by_employee_id', $em['id']) ?>><?= esc($em['full_name'] . ' (' . $em['employee_code'] . ')') ?></option><?php endforeach ?></select></div>
            <div class="col-md-6"><label class="form-label" for="bills">Bill / receipt (PDF or image)</label><input type="file" class="form-control" id="bills" name="bills[]" accept=".pdf,.png,.jpg,.jpeg" multiple></div>
            <div class="col-md-6"><label class="form-label" for="notes">Notes</label><input class="form-control" id="notes" name="notes" maxlength="500" value="<?= $v('notes') ?>"></div>
        </div>
    </div></div>
</div>
<div class="col-xl-4">
    <div class="u-pos-sticky u-top-76px card totals-box"><div class="card-body">
        <div class="card-section-title">Summary (estimate — server recalculates)</div>
        <div class="d-flex justify-content-between"><span>Amount before GST</span><span data-s="taxable">₹0.00</span></div>
        <div class="d-flex justify-content-between"><span>GST</span><span data-s="tax">₹0.00</span></div>
        <div class="d-flex justify-content-between border-top mt-2 pt-2 fw-semibold"><span>Total payable</span><span data-s="total">₹0.00</span></div>
        <div class="d-flex justify-content-between mt-2"><span>Input tax credit</span><span data-s="itc">₹0.00</span></div>
        <div class="small text-muted mt-2">ITC follows the accountant-approved rule for the category. It is zero without a vendor GSTIN. Approval posts: Dr expense (+ eligible input tax) / Cr vendor payable.</div>
    </div></div>
</div>
</div>
<div class="sticky-actions"><a href="<?= site_url($e ? 'finance/expenses/' . $e['id'] : 'finance/expenses') ?>" class="btn btn-outline-secondary">Cancel</a><button class="btn btn-primary" type="submit"><?= $e ? 'Save changes' : 'Record & send for approval' ?></button></div>
</form>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="<?= asset('js/expense-form.js') ?>"></script><?= $this->endSection() ?>
