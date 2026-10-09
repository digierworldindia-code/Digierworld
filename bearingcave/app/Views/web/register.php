<?= $this->extend('layouts/auth') ?>
<?= $this->section('content') ?>
<?php $supplier = $type === 'supplier'; ?>
<div class="bc-card"><div class="bc-card-body p-4">
    <p class="small-caps mb-1"><?= $supplier ? 'Supplier' : 'Buyer' ?> registration · free</p>
    <h1 class="h4 mb-1"><?= $supplier ? 'List your surplus inventory' : 'Start sourcing automotive parts' ?></h1>
    <p class="text-muted small mb-4"><?= $supplier ? 'Your company stays anonymous to buyers until you choose otherwise. Verification can be completed later from your dashboard.' : 'Browse, request quotations and order. Verify your company later for restricted inventory and enhanced features.' ?></p>
    <form method="post" action="<?= site_url('register/' . $type) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="visually-hidden" aria-hidden="true"><label for="website">Website</label><input type="text" id="website" name="website" tabindex="-1" autocomplete="off"></div>
        <fieldset class="mb-2"><legend class="h6 text-navy">Company</legend>
            <?= field('legal_name', 'Registered company name', null, ['required' => true, 'attrs' => 'autocomplete="organization"']) ?>
            <div class="row g-2">
                <div class="col-sm-6"><?= select_field('country_code', 'Country', country_options(), null, ['required' => true, 'placeholder' => 'Select country']) ?></div>
                <div class="col-sm-6"><?= field('city', 'City', null, ['attrs' => 'autocomplete="address-level2"']) ?></div>
            </div>
            <?php if ($supplier): ?><?= select_field('supplier_type', 'Business type', ['manufacturer' => 'Manufacturer', 'oem_supplier' => 'OEM supplier', 'aftermarket_supplier' => 'Aftermarket supplier', 'distributor' => 'Distributor', 'trader' => 'Trader', 'stockist' => 'Stockist', 'other' => 'Other'], 'manufacturer', ['required' => true]) ?><?php endif ?>
        </fieldset>
        <fieldset><legend class="h6 text-navy">Your login</legend>
            <div class="row g-2">
                <div class="col-sm-6"><?= field('email', 'Work email', null, ['type' => 'email', 'required' => true, 'attrs' => 'autocomplete="email"']) ?></div>
                <div class="col-sm-6"><?= field('phone', 'Phone', null, ['type' => 'tel', 'required' => true, 'attrs' => 'autocomplete="tel"']) ?></div>
            </div>
            <?= field('job_title', 'Job title', null) ?>
            <div class="row g-2">
                <div class="col-sm-6"><?= field('password', 'Password', null, ['type' => 'password', 'required' => true, 'attrs' => 'autocomplete="new-password" minlength="10"', 'help' => 'At least 10 characters; avoid common words.']) ?></div>
                <div class="col-sm-6"><?= field('password_confirm', 'Confirm password', null, ['type' => 'password', 'required' => true, 'attrs' => 'autocomplete="new-password"']) ?></div>
            </div>
        </fieldset>
        <div class="form-check mb-3">
            <input class="form-check-input<?= isset(form_errors()['terms']) ? ' is-invalid' : '' ?>" type="checkbox" value="1" id="terms" name="terms"<?= old('terms') ? ' checked' : '' ?> required>
            <label class="form-check-label small" for="terms">I agree to the <a href="<?= site_url('page/terms') ?>" target="_blank">Terms of Use</a> and <a href="<?= site_url('page/privacy') ?>" target="_blank">Privacy Policy</a>, and confirm I am authorised to register this company.</label>
            <?php if (isset(form_errors()['terms'])): ?><div class="invalid-feedback d-block"><?= esc(form_errors()['terms']) ?></div><?php endif ?>
        </div>
        <button class="btn btn-primary w-100" type="submit">Create free account</button>
    </form>
    <p class="text-center small mt-3 mb-0">Wrong account type? <a href="<?= site_url('register/' . ($supplier ? 'buyer' : 'supplier')) ?>">Register as a <?= $supplier ? 'buyer' : 'supplier' ?></a> · <a href="<?= site_url('login') ?>">Sign in</a></p>
</div></div>
<?= $this->endSection() ?>
