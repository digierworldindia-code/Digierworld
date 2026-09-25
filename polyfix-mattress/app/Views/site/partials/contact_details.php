<?php
use App\Libraries\Settings;

$phone   = Settings::get('company.support_phone');
$email   = Settings::get('company.support_email');
$address = Settings::get('company.address') ?: brand('location')['line'];
?>
<section class="section section-sand">
    <div class="container">
        <div class="row g-4">
            <div class="col-md-4"><div class="card h-100"><div class="card-body">
                <p class="eyebrow">Visit</p>
                <h3 class="h5"><?= esc(brand('name')) ?></h3>
                <address class="text-muted-ink mb-0"><?= esc($address) ?></address>
            </div></div></div>
            <div class="col-md-4"><div class="card h-100"><div class="card-body">
                <p class="eyebrow">Call</p>
                <?php if ($phone): ?><a class="h5 d-block" href="tel:<?= esc(preg_replace('/[^0-9+]/', '', $phone), 'attr') ?>"><?= esc($phone) ?></a><?php else: ?><p class="text-muted-ink mb-0">Use the form and we will call you back.</p><?php endif ?>
            </div></div></div>
            <div class="col-md-4"><div class="card h-100"><div class="card-body">
                <p class="eyebrow">Write</p>
                <?php if ($email): ?><a class="h5 d-block text-break" href="mailto:<?= esc($email, 'attr') ?>"><?= esc($email) ?></a><?php else: ?><p class="text-muted-ink mb-0">Use the form above; it reaches the same team.</p><?php endif ?>
            </div></div></div>
        </div>
    </div>
</section>
