<?= $this->extend('layouts/app') ?>
<?php
$d      = $doc ?? [];
$v      = static fn (string $k, $def = '') => esc((string) (old($k) ?? ($d[$k] ?? $def)));
$isNote = in_array($type, ['credit_note', 'debit_note'], true);
$rows   = old('lines') ?? ($lines ?: [['qty' => '1', 'tax_treatment' => 'regular', 'gst_rate' => '18.000', 'discount_type' => 'amount', 'discount_value' => '0']]);
$gst    = (string) (old('gst_type') ?? ($d['gst_type'] ?? ''));
$pos    = (string) (old('place_of_supply_state_code') ?? ($d['place_of_supply_state_code'] ?? ($contact['billing_state_code'] ?? '')));
$errs   = flash_errors();
$typeIcons = ['tax_invoice' => 'file-earmark-text', 'cash_memo' => 'cash-coin', 'bill_of_supply' => 'file-earmark', 'estimate' => 'clipboard-check', 'quotation' => 'chat-quote', 'proforma' => 'file-earmark-richtext', 'credit_note' => 'arrow-counterclockwise', 'debit_note' => 'arrow-clockwise'];
?>
<?= $this->section('content') ?>
<script type="application/json" id="billing-data"><?= json_encode([
    'services' => $services, 'docId' => $d['id'] ?? null, 'supplierState' => $company['state_code'] ?? null,
    'contact' => $contact, 'calcUrl' => site_url('billing/documents/calc'), 'lookupUrl' => site_url('contacts/lookup'), 'billingUrl' => site_url('contacts'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<?php if (empty($company['legal_name']) || empty($company['state_code'])): ?>
<div class="alert alert-warning small"><i class="bi bi-exclamation-triangle"></i> Company legal name/state are not set yet. You can save drafts, but statutory documents cannot be finalized until the <a href="<?= site_url('settings/setup') ?>">Billing Setup Wizard</a> is complete.</div>
<?php endif ?>

<form method="post" action="<?= site_url($doc ? 'billing/documents/' . $d['id'] : 'billing/documents') ?>" data-billing-form autocomplete="off">
<?= csrf_field() ?>
<?php if ($doc): ?><input type="hidden" name="lock_version" value="<?= (int) $d['lock_version'] ?>"><?php endif ?>
<input type="hidden" name="doc_type" value="<?= esc($type) ?>" data-doc-type>

<?php if (! $doc): ?>
<div class="card mb-3"><div class="card-body py-2">
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <span class="card-section-title mb-0 me-2">Document type</span>
        <?php foreach ($types as $k => $t): if ($k === 'debit_note' || $k === 'credit_note') { continue; } ?>
            <a href="<?= site_url('billing/documents/new?type=' . $k . ($contact ? '&contact=' . $contact['id'] : '')) ?>" class="btn btn-sm <?= $k === $type ? 'btn-primary' : 'btn-outline-secondary' ?>"><i class="bi bi-<?= $typeIcons[$k] ?? 'file' ?>"></i> <?= esc($t['label']) ?></a>
        <?php endforeach ?>
    </div>
    <?php if ($type === 'cash_memo'): ?><div class="small text-muted mt-2"><i class="bi bi-info-circle"></i> A cash memo has its own number series, but GST is decided exactly as for a tax invoice — by the nature of the supply, not by the payment mode.</div><?php endif ?>
    <?php if (in_array($type, ['estimate', 'quotation', 'proforma'], true)): ?><div class="small text-muted mt-2"><i class="bi bi-info-circle"></i> Estimates do not post to accounts or GST until converted into an invoice.</div><?php endif ?>
</div></div>
<?php else: ?>
<div class="mb-2"><span class="badge badge-neutral fs-6"><?= esc(doc_type_label($type)) ?></span> <span class="badge badge-neutral"><?= esc($d['draft_ref']) ?> · draft</span></div>
<?php endif ?>

<?php if ($isNote): ?>
<div class="alert alert-secondary border small"><i class="bi bi-link-45deg"></i> <?= esc(doc_type_label($type)) ?> against <strong><?= esc($original['document_number'] ?? '?') ?></strong> dated <?= dmy($original['issue_date'] ?? null) ?> (total <?= inr($original['grand_total'] ?? 0) ?>). GST type and place of supply must match the original.
    <input type="hidden" name="original_document_id" value="<?= (int) ($d['original_document_id'] ?? 0) ?>"></div>
<?php endif ?>

<div class="row g-3">
<div class="col-xxl-9">
    <!-- 1. Customer -->
    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">1. Customer</div>
        <input type="hidden" name="contact_id" value="<?= (int) ($contact['id'] ?? 0) ?>" data-contact-id>
        <div class="position-relative <?= $contact ? 'd-none' : '' ?>" data-customer-search>
            <input type="search" class="form-control form-control-lg<?= is_invalid('contact_id') ?>" placeholder="Type customer name, GSTIN or phone…" data-customer-input aria-label="Search customer">
            <div class="dropdown-menu w-100 shadow" data-customer-results></div>
            <?= field_error('contact_id') ?>
            <?php if (can('contacts.create')): ?><div class="form-text">Not in the list? <a href="<?= site_url('contacts/new') ?>" target="_blank" rel="noopener">Add a new client</a>, then search again.</div><?php endif ?>
        </div>
        <div class="<?= $contact ? '' : 'd-none' ?>" data-customer-card>
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="fw-bold fs-5" data-c="name"><?= esc($contact['legal_name'] ?? '' ?: ($contact['name'] ?? '')) ?></div>
                    <div class="u-ws-pre-line small" data-c="address"><?= esc($contact['billing_address'] ?? '') ?></div>
                    <div class="small"><span class="text-muted">GSTIN:</span> <span class="mono" data-c="gstin"><?= esc($contact['gstin'] ?? '' ?: 'Unregistered') ?></span> · <span class="text-muted">State:</span> <span data-c="state"><?= esc($contact['billing_state_code'] ?? '—') ?></span> · <span class="badge badge-neutral" data-c="ctype"><?= strtoupper($contact['customer_type'] ?? '') ?></span></div>
                    <div class="small text-muted" data-c="terms"><?= isset($contact['credit_days']) && $contact['credit_days'] !== null ? 'Credit terms: ' . (int) $contact['credit_days'] . ' days' : '' ?></div>
                </div>
                <?php if (! $isNote): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-change-customer>Change</button><?php endif ?>
            </div>
        </div>
    </div></div>

    <!-- 2. GST Type & Place of Supply (dedicated section) -->
    <div class="card mb-3 border-2 border-primary" data-gst-section><div class="card-body">
        <div class="card-section-title">2. GST type &amp; place of supply</div>
        <div class="row g-3 mb-2 small">
            <div class="col-md-6"><span class="text-muted">Supplier:</span> <strong><?= esc($company['legal_name'] ?? 'Company') ?></strong> · GSTIN <span class="mono"><?= esc($company['gstin'] ?? '—') ?></span> · <?= esc(($supplierState['name'] ?? '?') . ' (' . ($company['state_code'] ?? '?') . ')') ?></div>
            <div class="col-md-6"><span class="text-muted">Recipient:</span> <span data-recipient-line><?= $contact ? esc(($contact['gstin'] ? 'Registered · GSTIN ' . $contact['gstin'] : 'Unregistered') . ' · state ' . ($contact['billing_state_code'] ?? '?')) : 'choose a customer' ?></span></div>
        </div>
        <div class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label required" for="place_of_supply_state_code">Place of supply</label>
                <select class="form-select<?= is_invalid('place_of_supply_state_code') ?>" id="place_of_supply_state_code" name="place_of_supply_state_code" data-calc <?= $isNote ? 'data-locked' : '' ?>>
                    <option value="">— select —</option>
                    <?php foreach ($states as $s): ?><option value="<?= $s['code'] ?>" <?= $pos === $s['code'] ? 'selected' : '' ?>><?= esc($s['code'] . ' — ' . $s['name']) ?><?= $s['uses_utgst'] ? ' (UT)' : '' ?></option><?php endforeach ?>
                </select>
            </div>
            <div class="col-md-3"><label class="form-label" for="export_type">Export / SEZ</label>
                <select class="form-select" id="export_type" name="export_type" data-calc>
                    <option value="none">Not an export</option>
                    <option value="lut" <?= ($d['export_type'] ?? '') === 'lut' ? 'selected' : '' ?>>Export/SEZ under LUT (no IGST)</option>
                    <option value="with_payment" <?= ($d['export_type'] ?? '') === 'with_payment' ? 'selected' : '' ?>>Export/SEZ with IGST</option>
                </select></div>
            <div class="col-md-4 d-flex gap-3 flex-wrap">
                <div class="form-check"><input class="form-check-input" type="checkbox" id="is_sez_recipient" name="is_sez_recipient" value="1" <?= ! empty($d['is_sez_recipient']) ? 'checked' : '' ?> data-calc><label class="form-check-label small" for="is_sez_recipient">Recipient is an SEZ unit</label></div>
                <div class="form-check"><input class="form-check-input" type="checkbox" id="reverse_charge" name="reverse_charge" value="1" <?= ! empty($d['reverse_charge']) ? 'checked' : '' ?> data-calc><label class="form-check-label small" for="reverse_charge">Reverse charge</label></div>
            </div>
        </div>
        <div class="tax-advice mt-3 small d-none" data-advice-explain></div>

        <fieldset class="mt-3">
            <legend class="form-label mb-2 fs-6 required">GST type (choose one)</legend>
            <div class="gst-cards" role="radiogroup">
                <?php foreach (['cgst_sgst' => ['CGST + SGST', 'Intra-state supply'], 'igst' => ['IGST', 'Inter-state supply / SEZ / export'], 'cgst_utgst' => ['CGST + UTGST', 'Intra-Union Territory supply'], 'none' => ['No GST', 'All lines exempt / nil / non-GST / LUT / RCM']] as $k => [$title, $sub]): ?>
                <label class="gst-card <?= $gst === $k ? 'selected' : '' ?>" data-gst-card="<?= $k ?>">
                    <input type="radio" name="gst_type" value="<?= $k ?>" <?= $gst === $k ? 'checked' : '' ?> data-calc <?= $isNote ? 'data-locked' : '' ?>>
                    <div class="gst-title"><?= $title ?></div>
                    <div class="gst-sub"><?= $sub ?></div>
                    <div class="gst-calc" data-gst-preview="<?= $k ?>">—</div>
                </label>
                <?php endforeach ?>
            </div>
        </fieldset>
        <div class="mt-3" data-advice-messages></div>
    </div></div>

    <!-- 3. Line items -->
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="card-section-title mb-0">3. Line items</div>
            <div class="small text-muted d-none d-md-block">Enter = next field · Ctrl+Enter = new line · Alt+↑/↓ = move line</div>
        </div>
        <?= field_error('lines') ?>
        <div class="table-responsive">
        <table class="table table-sm line-items align-middle mb-2" data-lines>
            <thead><tr><th class="u-w-24p">Service / description</th><th class="u-w-8p">SAC</th><th class="u-w-7p">Qty</th><th class="u-w-7p">Unit</th><th class="u-w-10p">Rate ₹</th><th class="u-w-11p">Discount</th><th class="u-w-12p">Treatment</th><th class="u-w-8p">GST %</th><th class="u-w-10p num">Taxable</th><th class="u-w-10p num">Total</th><th></th></tr></thead>
            <tbody data-line-body>
            <?php foreach (array_values($rows) as $i => $l): ?>
                <tr data-line>
                    <td>
                        <input type="hidden" name="lines[<?= $i ?>][service_id]" value="<?= esc((string) ($l['service_id'] ?? '')) ?>" data-f="service_id">
                        <input class="form-control form-control-sm mb-1<?= isset($errs["lines.{$i}.item_name"]) ? ' is-invalid' : '' ?>" name="lines[<?= $i ?>][item_name]" value="<?= esc((string) ($l['item_name'] ?? '')) ?>" placeholder="Service name" list="svc-list" data-f="item_name" aria-label="Service name">
                        <textarea class="form-control form-control-sm li-desc" name="lines[<?= $i ?>][description]" rows="1" placeholder="Description (printed)" data-f="description" aria-label="Description"><?= esc((string) ($l['description'] ?? '')) ?></textarea>
                    </td>
                    <td><input class="form-control form-control-sm mono" name="lines[<?= $i ?>][sac_code]" value="<?= esc((string) ($l['sac_code'] ?? '')) ?>" data-f="sac_code" inputmode="numeric" aria-label="SAC"></td>
                    <td><input class="form-control form-control-sm text-end<?= isset($errs["lines.{$i}.qty"]) ? ' is-invalid' : '' ?>" name="lines[<?= $i ?>][qty]" value="<?= esc(rtrim(rtrim((string) ($l['qty'] ?? '1'), '0'), '.') ?: '1') ?>" data-f="qty" data-calc inputmode="decimal" aria-label="Quantity"></td>
                    <td><input class="form-control form-control-sm" name="lines[<?= $i ?>][unit]" value="<?= esc((string) ($l['unit'] ?? '')) ?>" data-f="unit" aria-label="Unit"></td>
                    <td><input class="form-control form-control-sm text-end<?= isset($errs["lines.{$i}.unit_rate"]) ? ' is-invalid' : '' ?>" name="lines[<?= $i ?>][unit_rate]" value="<?= esc(isset($l['unit_rate']) ? rtrim(rtrim((string) $l['unit_rate'], '0'), '.') : '') ?>" data-f="unit_rate" data-calc inputmode="decimal" aria-label="Rate"></td>
                    <td><div class="input-group input-group-sm">
                        <input class="form-control text-end" name="lines[<?= $i ?>][discount_value]" value="<?= esc(rtrim(rtrim((string) ($l['discount_value'] ?? '0'), '0'), '.') ?: '0') ?>" data-f="discount_value" data-calc inputmode="decimal" aria-label="Discount">
                        <select class="u-mxw-52px form-select" name="lines[<?= $i ?>][discount_type]" data-f="discount_type" data-calc aria-label="Discount type"><option value="amount">₹</option><option value="percent" <?= ($l['discount_type'] ?? '') === 'percent' ? 'selected' : '' ?>>%</option></select>
                    </div></td>
                    <td><select class="form-select form-select-sm" name="lines[<?= $i ?>][tax_treatment]" data-f="tax_treatment" data-calc aria-label="Tax treatment">
                        <?php foreach ($treatments as $t): ?><option value="<?= esc($t['code']) ?>" <?= ($l['tax_treatment'] ?? 'regular') === $t['code'] ? 'selected' : '' ?>><?= esc($t['label']) ?></option><?php endforeach ?></select></td>
                    <td><select class="form-select form-select-sm<?= isset($errs["lines.{$i}.gst_rate"]) ? ' is-invalid' : '' ?>" name="lines[<?= $i ?>][gst_rate]" data-f="gst_rate" data-calc aria-label="GST rate">
                        <?php foreach ($rates as $r): ?><option value="<?= esc($r['rate']) ?>" <?= \App\Libraries\Dec::cmp($l['gst_rate'] ?? '18', $r['rate']) === 0 ? 'selected' : '' ?>><?= esc(\App\Libraries\Dec::rateLabel($r['rate'])) ?>%</option><?php endforeach ?></select></td>
                    <td class="num small" data-out="taxable_value">—</td>
                    <td class="num small fw-semibold" data-out="line_total">—</td>
                    <td><button type="button" class="btn btn-sm btn-link text-danger p-0" data-remove-line aria-label="Remove line"><i class="bi bi-x-lg"></i></button></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        </div>
        <datalist id="svc-list"><?php foreach ($services as $s): ?><option value="<?= esc($s['name']) ?>"><?php endforeach ?></datalist>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-add-line><i class="bi bi-plus-lg"></i> Add line</button>
        <span class="small text-muted ms-2">Pick a catalogue service to auto-fill description, SAC, unit, rate and GST (all editable).</span>
    </div></div>

    <!-- 4. Notes -->
    <div class="card mb-3"><div class="card-body"><div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="notes">Notes (printed)</label><textarea class="form-control" id="notes" name="notes" rows="2"><?= $v('notes') ?></textarea></div>
        <div class="col-md-6"><label class="form-label" for="terms">Terms (printed)</label><textarea class="form-control" id="terms" name="terms" rows="2" placeholder="Defaults to company terms"><?= $v('terms') ?></textarea></div>
        <div class="col-12"><label class="form-label" for="internal_notes">Internal notes (never printed)</label><input class="form-control" id="internal_notes" name="internal_notes" value="<?= $v('internal_notes') ?>"></div>
    </div></div></div>
</div>

<div class="col-xxl-3">
    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">Dates &amp; references</div>
        <div class="mb-2"><label class="form-label" for="issue_date">Issue date</label><input type="date" class="form-control" id="issue_date" name="issue_date" value="<?= $v('issue_date', date('Y-m-d')) ?>" data-calc></div>
        <?php if (! in_array($type, ['credit_note'], true)): ?>
        <div class="mb-2"><label class="form-label" for="due_date"><?= in_array($type, ['estimate', 'quotation', 'proforma'], true) ? 'Valid till' : 'Due date' ?></label><input type="date" class="form-control" id="due_date" name="due_date" value="<?= $v('due_date') ?>"><div class="form-text">Blank = customer credit terms.</div></div>
        <?php endif ?>
        <div class="row g-2 mb-2">
            <div class="col-6"><label class="form-label small" for="service_period_from">Service from</label><input type="date" class="form-control form-control-sm" id="service_period_from" name="service_period_from" value="<?= $v('service_period_from') ?>"></div>
            <div class="col-6"><label class="form-label small" for="service_period_to">Service to</label><input type="date" class="form-control form-control-sm" id="service_period_to" name="service_period_to" value="<?= $v('service_period_to') ?>"></div>
        </div>
        <div class="mb-2"><label class="form-label" for="po_number">PO number</label><input class="form-control" id="po_number" name="po_number" value="<?= $v('po_number') ?>"></div>
        <div class="mb-2"><label class="form-label" for="reference_no">Reference</label><input class="form-control" id="reference_no" name="reference_no" value="<?= $v('reference_no') ?>"></div>
        <div class="mb-2"><label class="form-label" for="price_mode">Rates are</label><select class="form-select" id="price_mode" name="price_mode" data-calc>
            <option value="exclusive">Exclusive of GST</option><option value="inclusive" <?= ($d['price_mode'] ?? ($company['default_price_mode'] ?? '')) === 'inclusive' ? 'selected' : '' ?>>Inclusive of GST</option></select></div>
        <?php if ($canChangeBank): ?>
            <div class="mb-2"><label class="form-label" for="bank_account_id">Bank shown on document</label><select class="form-select" id="bank_account_id" name="bank_account_id"><option value="">— none —</option>
                <?php foreach ($banks as $b): ?><option value="<?= $b['id'] ?>" <?= (string) ($d['bank_account_id'] ?? ($b['is_default_on_invoice'] ? $b['id'] : '')) === (string) $b['id'] ? 'selected' : '' ?>><?= esc($b['display_name']) ?></option><?php endforeach ?></select></div>
            <div class="mb-2"><label class="form-label" for="template_id">Template</label><select class="form-select" id="template_id" name="template_id">
                <?php foreach ($templates as $t): ?><option value="<?= $t['id'] ?>" <?= (int) ($d['template_id'] ?? ($company['default_template_id'] ?? 0)) === (int) $t['id'] ? 'selected' : '' ?>><?= esc($t['name']) ?> (v<?= (int) $t['version'] ?>)</option><?php endforeach ?></select></div>
        <?php else: ?>
            <div class="small text-muted">Bank details and template follow company defaults.</div>
        <?php endif ?>
    </div></div>

    <div class="totals-box mb-3" data-totals>
        <div class="card-section-title">Totals (calculated on server)</div>
        <div class="row-t"><span>Sub-total</span><span data-t="subtotal">—</span></div>
        <div class="row-t"><span>Discount</span><span data-t="discount_total">—</span></div>
        <div class="row-t"><span>Taxable value</span><span data-t="taxable_total">—</span></div>
        <div class="row-t" data-t-row="cgst"><span>CGST</span><span data-t="cgst_total">—</span></div>
        <div class="row-t" data-t-row="sgst"><span>SGST</span><span data-t="sgst_total">—</span></div>
        <div class="row-t" data-t-row="utgst"><span>UTGST</span><span data-t="utgst_total">—</span></div>
        <div class="row-t" data-t-row="igst"><span>IGST</span><span data-t="igst_total">—</span></div>
        <div class="row-t"><span>Round-off</span><span data-t="round_off">—</span></div>
        <div class="row-t grand"><span>Grand total</span><span data-t="grand_total">—</span></div>
        <div class="small text-muted mt-2" data-words></div>
        <div class="mt-2 small" data-tax-summary></div>
    </div>
</div>
</div>

<div class="sticky-actions">
    <div class="me-auto align-self-center small">Grand total <strong class="fs-6" data-t="grand_total">—</strong> <span class="text-muted">· tax <span data-t="tax_total">—</span></span></div>
    <a href="<?= site_url($doc ? 'billing/documents/' . $d['id'] : 'billing/documents') ?>" class="btn btn-outline-secondary">Cancel</a>
    <button class="btn btn-outline-secondary" name="after" value="view">Save draft</button>
    <button class="btn btn-primary" name="after" value="finalize" title="Save, then review and finalize">Save &amp; review for finalizing →</button>
</div>
</form>
<template id="line-template">
    <tr data-line>
        <td><input type="hidden" data-f="service_id"><input class="form-control form-control-sm mb-1" placeholder="Service name" list="svc-list" data-f="item_name" aria-label="Service name"><textarea class="form-control form-control-sm li-desc" rows="1" placeholder="Description (printed)" data-f="description" aria-label="Description"></textarea></td>
        <td><input class="form-control form-control-sm mono" data-f="sac_code" inputmode="numeric" aria-label="SAC"></td>
        <td><input class="form-control form-control-sm text-end" value="1" data-f="qty" data-calc inputmode="decimal" aria-label="Quantity"></td>
        <td><input class="form-control form-control-sm" data-f="unit" aria-label="Unit"></td>
        <td><input class="form-control form-control-sm text-end" data-f="unit_rate" data-calc inputmode="decimal" aria-label="Rate"></td>
        <td><div class="input-group input-group-sm"><input class="form-control text-end" value="0" data-f="discount_value" data-calc inputmode="decimal" aria-label="Discount"><select class="u-mxw-52px form-select" data-f="discount_type" data-calc aria-label="Discount type"><option value="amount">₹</option><option value="percent">%</option></select></div></td>
        <td><select class="form-select form-select-sm" data-f="tax_treatment" data-calc aria-label="Tax treatment"><?php foreach ($treatments as $t): ?><option value="<?= esc($t['code']) ?>"><?= esc($t['label']) ?></option><?php endforeach ?></select></td>
        <td><select class="form-select form-select-sm" data-f="gst_rate" data-calc aria-label="GST rate"><?php foreach ($rates as $r): ?><option value="<?= esc($r['rate']) ?>" <?= \App\Libraries\Dec::cmp($r['rate'], '18') === 0 ? 'selected' : '' ?>><?= esc(\App\Libraries\Dec::rateLabel($r['rate'])) ?>%</option><?php endforeach ?></select></td>
        <td class="num small" data-out="taxable_value">—</td>
        <td class="num small fw-semibold" data-out="line_total">—</td>
        <td><button type="button" class="btn btn-sm btn-link text-danger p-0" data-remove-line aria-label="Remove line"><i class="bi bi-x-lg"></i></button></td>
    </tr>
</template>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="<?= asset('js/billing-form.js') ?>"></script><?= $this->endSection() ?>
