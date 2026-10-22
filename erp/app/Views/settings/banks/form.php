<?= $this->extend('layouts/app') ?>
<?php $v = static fn (string $k, $d = '') => esc((string) (old($k) ?? ($a[$k] ?? $d))); $sel = static fn (string $k, string $val, string $d = '') => ((string) (old($k) ?? ($a[$k] ?? $d))) === $val ? 'selected' : ''; ?>
<?= $this->section('content') ?>
<?php if ($wizard): ?><?= view('settings/_wizard', ['current' => 'banks']) ?><?php endif ?>
<?php if (! \App\Services\Settings\SettingsStore::encryptionReady()): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i> This server has no encryption key yet, so account numbers and CIF cannot be saved. The administrator must run <span class="mono">php spark key:generate</span> once in the application folder and keep a safe copy of <span class="mono">.env</span>.</div>
<?php endif ?>
<form method="post" action="<?= site_url($a ? 'settings/banks/' . $a['id'] : 'settings/banks') ?>" autocomplete="off">
<?= csrf_field() ?>
<?php if ($wizard): ?><input type="hidden" name="wizard" value="1"><?php endif ?>
<div class="row g-3">
<div class="col-lg-7"><div class="card"><div class="card-body">
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label required" for="display_name">Display name</label><input class="form-control<?= is_invalid('display_name') ?>" id="display_name" name="display_name" value="<?= $v('display_name') ?>" maxlength="120" placeholder="e.g. HDFC Current A/c" required><?= field_error('display_name') ?></div>
        <div class="col-md-3"><label class="form-label" for="account_kind">Type</label><select class="form-select" id="account_kind" name="account_kind">
            <?php foreach (['bank' => 'Bank account', 'upi_clearing' => 'UPI / wallet clearing', 'card_clearing' => 'Card settlement', 'cash' => 'Cash', 'other' => 'Other'] as $k => $l): ?><option value="<?= $k ?>" <?= $sel('account_kind', $k, 'bank') ?>><?= $l ?></option><?php endforeach ?></select></div>
        <div class="col-md-3"><label class="form-label" for="ownership">Ownership</label><select class="form-select" id="ownership" name="ownership">
            <option value="company" <?= $sel('ownership', 'company', 'company') ?>>Company</option>
            <option value="owner_personal" <?= $sel('ownership', 'owner_personal') ?>>Owner's personal</option>
            <option value="other" <?= $sel('ownership', 'other') ?>>Other</option></select></div>
        <div class="col-12" data-show-when="ownership=owner_personal">
            <div class="alert alert-warning small mb-2"><i class="bi bi-exclamation-triangle"></i> A personal savings account is <strong>not</strong> interchangeable with a company account. Receipts into it are posted to an <em>owner clearing</em> ledger and flagged for reconciliation. Record the treatment your accountant approved.</div>
            <label class="form-label required" for="classification_note">Accountant-approved classification</label><input class="form-control<?= is_invalid('classification_note') ?>" id="classification_note" name="classification_note" value="<?= $v('classification_note') ?>" maxlength="255" placeholder="e.g. Owner clearing — settled to current A/c monthly; reviewed by CA on 01-10-2026"><?= field_error('classification_note') ?>
            <?php if ($isSuper): ?>
            <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="print_approved" name="print_approved" value="1" <?= ! empty($a['print_approved_by']) ? 'checked' : '' ?>><label class="form-check-label small" for="print_approved">I (Owner) approve printing this personal account's details on invoices</label></div>
            <?php else: ?><div class="small text-muted mt-2">Only an Owner/Super Admin can approve printing a personal account on invoices.</div><?php endif ?>
        </div>
        <div class="col-md-6"><label class="form-label" for="bank_name">Bank name</label><input class="form-control<?= is_invalid('bank_name') ?>" id="bank_name" name="bank_name" value="<?= $v('bank_name') ?>" maxlength="120"><?= field_error('bank_name') ?></div>
        <div class="col-md-6"><label class="form-label" for="beneficiary_name">Beneficiary name (as per bank)</label><input class="form-control<?= is_invalid('beneficiary_name') ?>" id="beneficiary_name" name="beneficiary_name" value="<?= $v('beneficiary_name') ?>" maxlength="150"><?= field_error('beneficiary_name') ?></div>
        <div class="col-md-6"><label class="form-label" for="account_number">Account number <?= $a && $a['account_last4'] ? '<span class="text-muted fw-normal">(currently ' . mask_tail($a['account_last4']) . ' — leave blank to keep)</span>' : '' ?></label><input class="form-control mono<?= is_invalid('account_number') ?>" id="account_number" name="account_number" inputmode="numeric" autocomplete="off" maxlength="30"><?= field_error('account_number') ?></div>
        <div class="col-md-3"><label class="form-label" for="ifsc">IFSC</label><input class="form-control mono text-uppercase<?= is_invalid('ifsc') ?>" id="ifsc" name="ifsc" value="<?= $v('ifsc') ?>" maxlength="11"><?= field_error('ifsc') ?></div>
        <div class="col-md-3"><label class="form-label" for="account_type">Account type</label><select class="form-select" id="account_type" name="account_type"><option value="">—</option>
            <?php foreach (['current' => 'Current', 'savings' => 'Savings', 'od' => 'Overdraft', 'cc' => 'Cash credit', 'wallet' => 'Wallet', 'other' => 'Other'] as $k => $l): ?><option value="<?= $k ?>" <?= $sel('account_type', $k) ?>><?= $l ?></option><?php endforeach ?></select></div>
        <div class="col-md-6"><label class="form-label" for="branch">Branch</label><input class="form-control<?= is_invalid('branch') ?>" id="branch" name="branch" value="<?= $v('branch') ?>" maxlength="120"><?= field_error('branch') ?></div>
        <div class="col-md-6"><label class="form-label" for="upi_id">UPI ID (optional)</label><input class="form-control<?= is_invalid('upi_id') ?>" id="upi_id" name="upi_id" value="<?= $v('upi_id') ?>" maxlength="100" placeholder="business@bank"><?= field_error('upi_id') ?></div>
        <div class="col-md-6"><label class="form-label" for="cif">CIF / Customer Information File no. <i class="bi bi-lock-fill"></i> <?= $a && $a['cif_enc'] ? '<span class="text-muted fw-normal">(stored — leave blank to keep)</span>' : '' ?></label><input class="form-control mono<?= is_invalid('cif') ?>" id="cif" name="cif" autocomplete="off" maxlength="40"><?= field_error('cif') ?><div class="form-text">Optional, confidential, internal only. Never printed or exported.</div></div>
    </div>
</div></div></div>
<div class="col-lg-5"><div class="card"><div class="card-body">
    <div class="card-section-title">Invoice printing</div>
    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="show_on_invoice" name="show_on_invoice" value="1" <?= ! empty($a['show_on_invoice']) ? 'checked' : '' ?>><label class="form-check-label" for="show_on_invoice">Allow printing on invoices</label></div>
    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="is_default_on_invoice" name="is_default_on_invoice" value="1" <?= ! empty($a['is_default_on_invoice']) ? 'checked' : '' ?>><label class="form-check-label" for="is_default_on_invoice">Default bank on new invoices</label></div>
    <div class="mt-2"><label class="form-label" for="display_priority">Display priority</label><input type="number" class="form-control<?= is_invalid('display_priority') ?>" id="display_priority" name="display_priority" value="<?= $v('display_priority', '100') ?>" min="0" max="9999"><?= field_error('display_priority') ?></div>
    <div class="card-section-title mt-4">Opening balance</div>
    <div class="row g-2">
        <div class="col-6"><label class="form-label" for="opening_balance">Amount (₹)</label><input class="form-control<?= is_invalid('opening_balance') ?>" id="opening_balance" name="opening_balance" value="<?= $v('opening_balance', '0.00') ?>" inputmode="decimal"><?= field_error('opening_balance') ?></div>
        <div class="col-6"><label class="form-label" for="opening_date">As on</label><input type="date" class="form-control<?= is_invalid('opening_date') ?>" id="opening_date" name="opening_date" value="<?= $v('opening_date') ?>"><?= field_error('opening_date') ?></div>
    </div>
    <div class="form-check mt-3"><input class="form-check-input" type="checkbox" id="requires_review" name="requires_review" value="1" <?= ! empty($a['requires_review']) ? 'checked' : '' ?>><label class="form-check-label small" for="requires_review">Flag receipts into this account for reconciliation review</label></div>
    <?php if ($a): ?><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= $a['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="is_active">Active</label></div><?php endif ?>
</div></div></div>
</div>
<div class="sticky-actions"><a href="<?= site_url('settings/banks') ?>" class="btn btn-outline-secondary">Cancel</a><button class="btn btn-primary">Save account</button></div>
</form>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="<?= asset('js/settings.js') ?>"></script><?= $this->endSection() ?>
