<?php /** @var array $section @var bool $enabled */ ?>
<section class="section section-sand" id="apply">
    <div class="container">
        <div class="row g-5">
            <div class="col-lg-4">
                <h2><?= esc($section['heading'] ?? 'Become a dealer') ?></h2>
                <?php if (! empty($section['body'])): ?><p class="text-muted-ink mt-3"><?= esc($section['body']) ?></p><?php endif ?>
            </div>
            <div class="col-lg-8">
                <?php if (! $enabled): ?>
                <div class="alert alert-secondary">Dealership applications are closed at the moment.</div>
                <?php else: ?>
                <form class="card" method="post" action="<?= site_url('dealers/apply') ?>" novalidate>
                    <div class="card-body row g-3">
                        <?= csrf_field() ?>
                        <div class="hp-field" aria-hidden="true"><label for="website-apply">Leave this empty</label><input id="website-apply" name="website" tabindex="-1" autocomplete="off"></div>
                        <?php
                        $text = static function (string $name, string $label, string $attrs = '', string $col = 'col-md-6'): string {
                            return '<div class="' . $col . '"><label class="form-label" for="' . $name . '">' . esc($label) . '</label>'
                                . '<input class="form-control' . invalid($name) . '" id="' . $name . '" name="' . $name . '" ' . $attrs
                                . ' value="' . esc(old($name, ''), 'attr') . '">' . field_error($name) . '</div>';
                        };
                        ?>
                        <?= $text('business_name', 'Business name', 'maxlength="180" required autocomplete="organization"') ?>
                        <?= $text('owner_name', 'Owner name', 'maxlength="160" required autocomplete="name"') ?>
                        <?= $text('mobile', 'Mobile number', 'type="tel" inputmode="tel" maxlength="16" required autocomplete="tel"') ?>
                        <?= $text('email', 'Email', 'type="email" maxlength="255" required autocomplete="email"') ?>
                        <?= $text('city', 'City', 'maxlength="80" required autocomplete="address-level2"') ?>
                        <?= $text('state', 'State', 'maxlength="80" required autocomplete="address-level1"') ?>
                        <div class="col-12">
                            <label class="form-label" for="address">Business address</label>
                            <textarea class="form-control<?= invalid('address') ?>" id="address" name="address" rows="2" minlength="10" maxlength="400" required autocomplete="street-address"><?= esc(old('address', '')) ?></textarea>
                            <?= field_error('address') ?>
                        </div>
                        <?= $text('gst_number', 'GSTIN (optional)', 'maxlength="15" autocapitalize="characters"') ?>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input type="hidden" name="has_existing_business" value="0">
                                <input class="form-check-input" type="checkbox" id="has_existing_business" name="has_existing_business" value="1"<?= old('has_existing_business') === '1' ? ' checked' : '' ?>>
                                <label class="form-check-label" for="has_existing_business">I already run a retail business</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="existing_business_details">Existing business (optional)</label>
                            <textarea class="form-control<?= invalid('existing_business_details') ?>" id="existing_business_details" name="existing_business_details" rows="2" maxlength="1000"><?= esc(old('existing_business_details', '')) ?></textarea>
                            <?= field_error('existing_business_details') ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="apply-message">Anything else (optional)</label>
                            <textarea class="form-control<?= invalid('message') ?>" id="apply-message" name="message" rows="3" maxlength="2000"><?= esc(old('message', '')) ?></textarea>
                            <?= field_error('message') ?>
                        </div>
                        <div class="col-12"><button class="btn btn-primary" type="submit">Send application</button></div>
                    </div>
                </form>
                <?php endif ?>
            </div>
        </div>
    </div>
</section>
