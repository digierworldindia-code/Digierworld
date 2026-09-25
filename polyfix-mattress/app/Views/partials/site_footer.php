<?php
use App\Libraries\Settings;

$phone   = Settings::get('company.support_phone');
$email   = Settings::get('company.support_email');
$address = Settings::get('company.address') ?: brand('location')['line'];
$socials = array_filter([
    'Instagram' => Settings::get('social.instagram'),
    'Facebook'  => Settings::get('social.facebook'),
    'LinkedIn'  => Settings::get('social.linkedin'),
    'YouTube'   => Settings::get('social.youtube'),
]);
$ranges = db_connect()->table('products')->select('name, slug')->where(['status' => 'PUBLISHED', 'deleted_at' => null])
    ->orderBy('sort_order')->limit(6)->get()->getResultArray();
?>
<footer class="site-footer">
    <div class="container">
        <div class="row g-5">
            <div class="col-12 col-lg-4">
                <a class="wordmark text-decoration-none" href="<?= site_url('/') ?>"><?= brand_wordmark() ?></a>
                <p class="mt-3 small"><?= esc(\App\Libraries\Seo::siteDescription()) ?></p>
            </div>
            <div class="col-6 col-lg-2">
                <h2>Mattresses</h2>
                <ul>
                    <?php foreach ($ranges as $range): ?>
                    <li><a href="<?= site_url('mattresses/' . $range['slug']) ?>"><?= esc($range['name']) ?></a></li>
                    <?php endforeach ?>
                    <li><a href="<?= site_url('mattresses') ?>">All mattresses</a></li>
                </ul>
            </div>
            <div class="col-6 col-lg-2">
                <h2>Company</h2>
                <ul>
                    <li><a href="<?= site_url('about') ?>">About</a></li>
                    <li><a href="<?= site_url('why-polyfix') ?>">Why <?= esc(brand('shortName')) ?></a></li>
                    <li><a href="<?= site_url('dealers') ?>">Find a dealer</a></li>
                    <li><a href="<?= site_url('dealers#apply') ?>">Become a dealer</a></li>
                    <li><a href="<?= site_url('dealer/login') ?>">Dealer sign-in</a></li>
                </ul>
            </div>
            <div class="col-6 col-lg-2">
                <h2>Support</h2>
                <ul>
                    <li><a href="<?= site_url('warranty') ?>">Warranty</a></li>
                    <li><a href="<?= site_url('warranty') ?>#verify">Verify a mattress</a></li>
                    <li><a href="<?= site_url('contact') ?>">Contact</a></li>
                    <li><a href="<?= site_url('privacy') ?>">Privacy</a></li>
                    <li><a href="<?= site_url('terms') ?>">Terms</a></li>
                </ul>
            </div>
            <div class="col-6 col-lg-2">
                <h2>Reach us</h2>
                <address class="d-grid gap-2">
                    <span><?= esc($address) ?></span>
                    <?php if ($phone): ?><a href="tel:<?= esc(preg_replace('/[^0-9+]/', '', $phone), 'attr') ?>"><?= esc($phone) ?></a><?php endif ?>
                    <?php if ($email): ?><a href="mailto:<?= esc($email, 'attr') ?>"><?= esc($email) ?></a><?php endif ?>
                </address>
                <?php if ($socials !== []): ?>
                <ul class="mt-3">
                    <?php foreach ($socials as $name => $url): ?><li><a href="<?= esc($url, 'attr') ?>" rel="noopener" target="_blank"><?= esc($name) ?></a></li><?php endforeach ?>
                </ul>
                <?php endif ?>
            </div>
        </div>
        <div class="footer-bottom d-flex flex-wrap justify-content-between gap-3">
            <span>© <?= gmdate('Y') ?> <?= esc(brand('legalName')) ?>. All rights reserved.</span>
            <span><?= esc(brand('location')['line']) ?></span>
        </div>
    </div>
</footer>
