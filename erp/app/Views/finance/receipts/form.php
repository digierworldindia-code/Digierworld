<?= $this->extend('layouts/app') ?>
<?php
$v    = static fn (string $k, $d = '') => esc((string) (old($k) ?? $d));
$errs = flash_errors();
?>
<?= $this->section('content') ?>
<form method="post" action="<?= site_url('finance/receipts') ?>" data-receipt-form>
<?= csrf_field() ?>
<script type="application/json" id="receipt-data"><?= json_encode([
    'lookupUrl' => site_url('contacts/lookup'), 'openUrl' => site_url('finance/receipts/open-documents'),
    'contact' => $contact, 'focusDocument' => $focusDocument, 'canAllocate' => $canAllocate,
    'accounts' => array_map(static fn ($a) => ['id' => (int) $a['id'], 'kind' => $a['account_kind'], 'review' => (bool) $a['requires_review']], $accounts),
    'old' => ['alloc' => old('alloc') ?? []],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php if (isset($errs['duplicate'])): ?>
<div class="alert alert-warning"><i class="bi bi-files"></i> Possible duplicate of receipt <strong><?= esc($errs['duplicate']) ?></strong>.
    <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="allow_duplicate" value="1" id="allow_duplicate"><label class="form-check-label" for="allow_duplicate">This is a separate payment — record it anyway</label></div></div>
<?php endif ?>
<div class="row g-3">
<div class="col-xl-8">
    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">1. Customer</div>
        <input type="hidden" name="contact_id" value="<?= $v('contact_id', $contact['id'] ?? '') ?>" data-contact-id>
        <div class="<?= $contact ? 'd-none' : '' ?>" data-customer-search>
            <div class="dropdown">
                <input class="form-control<?= is_invalid('contact_id') ?>" placeholder="Type customer name, phone or GSTIN" autocomplete="off" data-customer-input aria-label="Customer">
                <div class="dropdown-menu w-100" data-customer-results></div>
            </div>
            <?= field_error('contact_id') ?>
        </div>
        <div class="d-flex justify-content-between align-items-start <?= $contact ? '' : 'd-none' ?>" data-customer-card>
            <div><div class="fw-semibold fs-5" data-c-name><?= esc($contact['name'] ?? '') ?></div><div class="small text-muted mono" data-c-meta><?= esc(($contact['contact_code'] ?? '') . ' · ' . ($contact['gstin'] ?? 'Unregistered')) ?></div></div>
            <button type="button" class="btn btn-outline-dark btn-sm" data-change-customer>Change</button>
        </div>
    </div></div>

    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">2. Payment</div>
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label required" for="amount">Amount received (₹)</label><input class="form-control form-control-lg<?= is_invalid('amount') ?>" id="amount" name="amount" inputmode="decimal" value="<?= $v('amount') ?>" required data-amount><?= field_error('amount') ?></div>
            <div class="col-md-4"><label class="form-label required" for="receipt_date">Date</label><input type="date" class="form-control<?= is_invalid('receipt_date') ?>" id="receipt_date" name="receipt_date" value="<?= $v('receipt_date', date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>" required><?= field_error('receipt_date') ?></div>
            <div class="col-md-4"><label class="form-label required" for="mode">Mode</label><select class="form-select<?= is_invalid('mode') ?>" id="mode" name="mode" required data-mode><option value="">— choose —</option><?php foreach ($modes as $k => $l): ?><option value="<?= $k ?>" <?= old('mode') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select><?= field_error('mode') ?></div>
            <div class="col-md-6"><label class="form-label required" for="bank_cash_account_id">Received into</label>
                <select class="form-select<?= is_invalid('bank_cash_account_id') ?>" id="bank_cash_account_id" name="bank_cash_account_id" required data-account>
                    <option value="">— choose —</option>
                    <?php foreach ($accounts as $a): ?><option value="<?= $a['id'] ?>" data-kind="<?= esc($a['account_kind']) ?>" <?= (string) old('bank_cash_account_id') === (string) $a['id'] ? 'selected' : '' ?>><?= esc($a['display_name']) ?><?= $a['ownership'] === 'owner_personal' ? ' (owner personal — flagged for review)' : '' ?></option><?php endforeach ?>
                </select><?= field_error('bank_cash_account_id') ?>
                <div class="form-text">Cash goes to a cash account. When it is banked, record a <em>Cash → Bank deposit</em> (not income).</div>
            </div>
            <div class="col-md-6"><label class="form-label" for="reference_no">Reference / UTR / cheque no.</label><input class="form-control" id="reference_no" name="reference_no" value="<?= $v('reference_no') ?>" maxlength="80"></div>
            <div class="col-md-4 d-none" data-cheque-only><label class="form-label" for="cheque_date">Cheque date</label><input type="date" class="form-control" id="cheque_date" name="cheque_date" value="<?= $v('cheque_date') ?>"></div>
            <div class="col-12"><label class="form-label" for="notes">Notes</label><input class="form-control" id="notes" name="notes" value="<?= $v('notes') ?>" maxlength="500"></div>
        </div>
    </div></div>

    <div class="card mb-3"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <div class="card-section-title mb-0">3. Apply to invoices</div>
            <?php if ($canAllocate): ?><div class="btn-group btn-group-sm"><button type="button" class="btn btn-outline-dark" data-auto-allocate>Auto-apply oldest first</button><button type="button" class="btn btn-outline-secondary" data-clear-allocate>Clear</button></div><?php endif ?>
        </div>
        <?php if (! $canAllocate): ?><div class="small text-muted">You can record the payment; an accountant applies it to invoices. The full amount is kept as an advance until then.</div><?php endif ?>
        <div class="table-responsive"><table class="table table-sm align-middle small mb-0">
            <thead><tr><th>Document</th><th>Date</th><th>Due</th><th class="num">Total</th><th class="num">Balance due</th><th class="num" style="width:150px">Apply now</th></tr></thead>
            <tbody data-open-docs><tr><td colspan="6" class="text-muted">Choose a customer to see unpaid invoices.</td></tr></tbody>
        </table></div>
        <div class="small text-muted mt-2" data-advances></div>
    </div></div>
</div>
<div class="col-xl-4">
    <div class="card totals-box mb-3" style="position:sticky;top:76px"><div class="card-body">
        <div class="card-section-title">Summary</div>
        <div class="d-flex justify-content-between"><span>Amount received</span><strong data-s-amount>₹0.00</strong></div>
        <div class="d-flex justify-content-between"><span>Applied to invoices</span><span data-s-applied>₹0.00</span></div>
        <div class="d-flex justify-content-between border-top mt-2 pt-2"><span>Kept as advance</span><strong data-s-advance>₹0.00</strong></div>
        <div class="alert alert-danger small mt-2 d-none" data-s-error></div>
        <div class="alert alert-warning small mt-2 d-none" data-s-review>This account is an owner personal account. The receipt will be flagged for accountant review.</div>
        <p class="small text-muted mt-2 mb-0">A receipt never creates a second sale: it settles the invoice balance (or is held as an advance). Revenue was recorded when the invoice was finalized.</p>
    </div></div>
</div>
</div>
<div class="sticky-actions">
    <a href="<?= site_url('finance/receipts') ?>" class="btn btn-outline-secondary">Cancel</a>
    <button type="submit" class="btn btn-dark" data-submit>Record receipt</button>
</div>
</form>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="<?= base_url('assets/js/receipt-form.js') ?>"></script><?= $this->endSection() ?>
