<?php
use App\Services\PublicFormService;

/** @var bool $enabled */
?>
<section class="section-tight" id="enquiry">
    <div class="container">
        <div class="row g-5">
            <div class="col-lg-7">
                <h2>Send us a message</h2>
                <?php if (! $enabled): ?>
                <div class="alert alert-secondary mt-3">The contact form is currently closed. Please call us instead.</div>
                <?php else: ?>
                <form class="card mt-4" method="post" action="<?= site_url('contact') ?>" novalidate>
                    <div class="card-body row g-3">
                        <?= csrf_field() ?>
                        <div class="hp-field" aria-hidden="true"><label for="website">Leave this empty</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
                        <div class="col-md-6">
                            <label class="form-label" for="name">Your name</label>
                            <input class="form-control<?= invalid('name') ?>" id="name" name="name" maxlength="160" required autocomplete="name" value="<?= esc(old('name', ''), 'attr') ?>">
                            <?= field_error('name') ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="phone">Mobile number</label>
                            <input class="form-control<?= invalid('phone') ?>" id="phone" name="phone" type="tel" inputmode="tel" maxlength="16" required autocomplete="tel" value="<?= esc(old('phone', ''), 'attr') ?>">
                            <?= field_error('phone') ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="email">Email <span class="text-muted-ink small">(optional)</span></label>
                            <input class="form-control<?= invalid('email') ?>" id="email" name="email" type="email" maxlength="255" autocomplete="email" value="<?= esc(old('email', ''), 'attr') ?>">
                            <?= field_error('email') ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="city">City <span class="text-muted-ink small">(optional)</span></label>
                            <input class="form-control<?= invalid('city') ?>" id="city" name="city" maxlength="80" autocomplete="address-level2" value="<?= esc(old('city', ''), 'attr') ?>">
                            <?= field_error('city') ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="requirement">What is it about?</label>
                            <select class="form-select<?= invalid('requirement') ?>" id="requirement" name="requirement" required>
                                <?php foreach (PublicFormService::REQUIREMENTS as $value => $label): ?>
                                <option value="<?= $value ?>"<?= old('requirement') === $value ? ' selected' : '' ?>><?= esc($label) ?></option>
                                <?php endforeach ?>
                            </select>
                            <?= field_error('requirement') ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="message">Message</label>
                            <textarea class="form-control<?= invalid('message') ?>" id="message" name="message" rows="5" minlength="10" maxlength="2000" required><?= esc(old('message', '')) ?></textarea>
                            <?= field_error('message') ?>
                        </div>
                        <div class="col-12 d-flex flex-wrap align-items-center gap-3">
                            <button class="btn btn-primary" type="submit">Send message</button>
                            <span class="small text-muted-ink">We reply within one working day. See our <a href="<?= site_url('privacy') ?>">privacy policy</a>.</span>
                        </div>
                    </div>
                </form>
                <?php endif ?>
            </div>
            <div class="col-lg-5">
                <h2 class="h3">Warranty question?</h2>
                <p class="text-muted-ink">Claims are raised by the dealer you bought from, against your mattress serial number. You can check the warranty status yourself at any time.</p>
                <a class="btn btn-outline-dark" href="<?= site_url('warranty') ?>">Verify a mattress</a>
            </div>
        </div>
    </div>
</section>
