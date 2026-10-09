<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?php $c = $company; $sup = $c['company_type'] === 'supplier'; $locked = $c['verification_status'] === 'verified'; ?>
<?= view('components/page_header', ['title' => 'Company profile', 'subtitle' => 'Keep your company details accurate — they are used for verification, invoices and (if you opt in) your public profile.']) ?>
<form method="post" action="<?= site_url($area . '/company') ?>" novalidate>
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-xl-8">
            <fieldset class="bc-fieldset"><legend>Legal details</legend>
                <?php if ($locked): ?><div class="alert alert-info small py-2"><i class="bi bi-lock me-1" aria-hidden="true"></i>Your company is verified. Legal name, country and registration number are locked — contact support to change them.</div><?php endif ?>
                <div class="row g-3">
                    <div class="col-md-6"><?= field('legal_name', 'Registered legal name', $c['legal_name'], ['required' => true, 'class' => '', 'attrs' => $locked ? 'readonly' : '']) ?></div>
                    <div class="col-md-6"><?= field('trade_name', 'Trading / brand name', $c['trade_name'], ['class' => '']) ?></div>
                    <div class="col-md-4"><?= field('registration_number', 'Company registration no.', $c['registration_number'], ['class' => '', 'attrs' => $locked ? 'readonly' : '']) ?></div>
                    <div class="col-md-4"><?= field('business_type', 'Legal form', $c['business_type'], ['class' => '', 'placeholder' => 'Pvt Ltd, LLC, GmbH…']) ?></div>
                    <div class="col-md-2"><?= field('year_established', 'Founded', $c['year_established'], ['type' => 'number', 'class' => '']) ?></div>
                    <div class="col-md-2"><?= field('employee_count', 'Employees', $c['employee_count'], ['class' => '', 'placeholder' => '50-200']) ?></div>
                </div>
            </fieldset>
            <fieldset class="bc-fieldset"><legend>Address &amp; contact</legend>
                <div class="row g-3">
                    <div class="col-md-8"><?= field('address_line1', 'Address line 1', $c['address_line1'], ['required' => true, 'class' => '', 'attrs' => 'autocomplete="address-line1"']) ?></div>
                    <div class="col-md-4"><?= field('address_line2', 'Address line 2', $c['address_line2'], ['class' => '']) ?></div>
                    <div class="col-md-4"><?= field('city', 'City', $c['city'], ['required' => true, 'class' => '']) ?></div>
                    <div class="col-md-3"><?= field('state', 'State / region', $c['state'], ['class' => '']) ?></div>
                    <div class="col-md-2"><?= field('postal_code', 'Postal code', $c['postal_code'], ['class' => '']) ?></div>
                    <div class="col-md-3"><?= select_field('country_code', 'Country', country_options(), $c['country_code'], ['required' => true, 'class' => '', 'attrs' => $locked ? 'disabled' : '']) ?><?php if ($locked): ?><input type="hidden" name="country_code" value="<?= esc($c['country_code'], 'attr') ?>"><?php endif ?></div>
                    <div class="col-md-4"><?= field('phone', 'Company phone', $c['phone'], ['type' => 'tel', 'required' => true, 'class' => '']) ?></div>
                    <div class="col-md-4"><?= field('email', 'Company email', $c['email'], ['type' => 'email', 'required' => true, 'class' => '']) ?></div>
                    <div class="col-md-4"><?= field('website', 'Website', $c['website'], ['type' => 'url', 'class' => '', 'placeholder' => 'https://']) ?></div>
                    <div class="col-12"><?= textarea_field('description', 'Company description', $c['description'], ['rows' => 3, 'class' => '']) ?></div>
                </div>
            </fieldset>
            <?php if ($sup): ?>
            <fieldset class="bc-fieldset"><legend>Supplier capabilities</legend>
                <div class="row g-3">
                    <div class="col-md-4"><?= select_field('supplier_type', 'Supplier type', ['manufacturer' => 'Manufacturer', 'oem_supplier' => 'OEM supplier', 'aftermarket_supplier' => 'Aftermarket supplier', 'distributor' => 'Distributor', 'trader' => 'Trader', 'stockist' => 'Stockist', 'other' => 'Other'], $profile['supplier_type'] ?? 'manufacturer', ['class' => '']) ?></div>
                    <div class="col-md-4"><?= field('production_capacity', 'Production capacity', $profile['production_capacity'] ?? '', ['class' => '']) ?></div>
                    <div class="col-md-4"><?= field('warehouse_count', 'Warehouses', $profile['warehouse_count'] ?? '', ['type' => 'number', 'class' => '']) ?></div>
                    <div class="col-md-6"><?= textarea_field('main_categories', 'Main product categories', $profile['main_categories'] ?? '', ['rows' => 2, 'class' => '']) ?></div>
                    <div class="col-md-6"><?= textarea_field('brands_handled', 'Brands handled', $profile['brands_handled'] ?? '', ['rows' => 2, 'class' => '']) ?></div>
                    <div class="col-md-6"><?= textarea_field('export_markets', 'Export markets', $profile['export_markets'] ?? '', ['rows' => 2, 'class' => '']) ?></div>
                    <div class="col-md-6"><?= select_field('logistics_preference', 'Logistics preference', ['platform_managed' => 'BearingCave-managed', 'supplier_direct' => 'Ship directly', 'both' => 'Either'], $profile['logistics_preference'] ?? 'platform_managed', ['class' => '']) ?>
                        <?= check_field('default_confidential', 'Hide my identity on new listings by default', (bool) ($profile['default_confidential'] ?? 1), ['class' => 'mt-2']) ?></div>
                </div>
            </fieldset>
            <?php else: ?>
            <fieldset class="bc-fieldset"><legend>Buying profile</legend>
                <div class="row g-3">
                    <div class="col-md-4"><?= select_field('buyer_category', 'Buyer type', ['oem_manufacturer' => 'Automotive / OEM manufacturer', 'component_manufacturer' => 'Component manufacturer', 'distributor' => 'Spare parts distributor', 'procurement' => 'Procurement company', 'trader' => 'Automotive trader', 'workshop_fleet' => 'Workshop / fleet', 'other' => 'Other'], $profile['buyer_category'] ?? '', ['class' => '', 'placeholder' => 'Select']) ?></div>
                    <div class="col-md-4"><?= field('annual_purchase_volume', 'Annual purchase volume', $profile['annual_purchase_volume'] ?? '', ['class' => '']) ?></div>
                    <div class="col-md-4"><?= select_field('preferred_currency', 'Preferred currency', currency_options(), $profile['preferred_currency'] ?? '', ['class' => '', 'placeholder' => 'Select']) ?></div>
                    <div class="col-12"><?= textarea_field('interested_categories', 'Categories of interest', $profile['interested_categories'] ?? '', ['rows' => 2, 'class' => '']) ?></div>
                </div>
            </fieldset>
            <?php endif ?>
        </div>
        <div class="col-xl-4">
            <div class="bc-card mb-3"><div class="bc-card-body">
                <h2 class="h6">Status</h2>
                <dl class="dl-grid small mb-0">
                    <dt>Verification</dt><dd><?= status_badge($c['verification_status']) ?></dd>
                    <dt>Account</dt><dd><?= status_badge($c['status']) ?></dd>
                    <dt>Public alias</dt><dd class="part-no"><?= esc($c['public_alias']) ?></dd>
                </dl>
                <p class="small text-muted mt-2 mb-0">Buyers see your alias instead of your name on confidential listings and RFQs.</p>
            </div></div>
            <?php if ($sup): ?>
            <div class="bc-card"><div class="bc-card-body">
                <h2 class="h6">Public visibility</h2>
                <?php if ($canPublic): ?>
                    <?= check_field('public_profile_enabled', 'Publish my company profile', (bool) $c['public_profile_enabled']) ?>
                    <?= $canContact ? check_field('show_contact_public', 'Show contact details to signed-in buyers', (bool) $c['show_contact_public']) : '' ?>
                    <p class="small text-muted mb-0">Individual listings can still be marked identity-confidential.</p>
                <?php else: ?>
                    <p class="small text-muted mb-2">Public profiles, branding and contact visibility are available to Verified Suppliers.</p>
                    <a class="btn btn-sm btn-outline-primary" href="<?= site_url('supplier/membership') ?>">View membership</a>
                <?php endif ?>
            </div></div>
            <?php endif ?>
        </div>
    </div>
    <div class="d-flex gap-2"><button class="btn btn-primary" type="submit">Save profile</button><a class="btn btn-light" href="<?= site_url($area . '/dashboard') ?>">Cancel</a></div>
</form>
<?= $this->endSection() ?>
