<?= $this->extend('layouts/site') ?>
<?= $this->section('content') ?>
<?php
/** @var array $hero @var array $sections @var array $slots @var array $stats @var array $faqs */
$cta = static fn (?array $c, string $class): string => empty($c['href']) ? ''
    : '<a class="btn ' . $class . '" href="' . esc(site_url(ltrim($c['href'], '/')), 'attr') . '">' . esc($c['label'] ?? '') . '</a>';
$image = $hero['image'] ?? null;
?>
<section class="hero">
    <div class="container">
        <div class="row g-5 align-items-center">
            <div class="col-lg-6">
                <?php if (! empty($hero['eyebrow'])): ?><p class="eyebrow"><?= esc($hero['eyebrow']) ?></p><?php endif ?>
                <h1><?= esc($hero['heading'] ?? brand('name')) ?></h1>
                <?php if (! empty($hero['body'])): ?><p class="lede mt-3"><?= esc($hero['body']) ?></p><?php endif ?>
                <div class="d-flex flex-wrap gap-3 mt-4">
                    <?= $cta($hero['primaryCta'] ?? null, 'btn-primary') ?>
                    <?= $cta($hero['secondaryCta'] ?? null, 'btn-outline-dark') ?>
                    <?= $cta($hero['tertiaryCta'] ?? null, 'btn-link-quiet') ?>
                </div>
                <dl class="hero__trust d-flex flex-wrap gap-4 mt-5 mb-0">
                    <div><dt class="visually-hidden">Ranges</dt><dd class="mb-0"><strong><?= (int) $stats['ranges'] ?></strong> mattress ranges</dd></div>
                    <?php if ($stats['maxYears'] !== null): ?>
                    <div><dt class="visually-hidden">Warranty</dt><dd class="mb-0"><strong>Up to <?= (int) $stats['maxYears'] ?> yrs</strong> warranty term</dd></div>
                    <?php endif ?>
                    <?php if ($stats['lowest'] !== null): ?>
                    <div><dt class="visually-hidden">Starting price</dt><dd class="mb-0"><strong><?= inr($stats['lowest']) ?></strong> starting from</dd></div>
                    <?php endif ?>
                </dl>
            </div>
            <?php if ($image): ?>
            <div class="col-lg-6 hero__media">
                <img class="img-fluid" src="<?= esc(base_url(ltrim($image['src'], '/')), 'attr') ?>" alt="<?= esc($image['alt'] ?? '', 'attr') ?>"
                     width="<?= (int) ($image['width'] ?? 1600) ?>" height="<?= (int) ($image['height'] ?? 1100) ?>" fetchpriority="high">
            </div>
            <?php endif ?>
        </div>
    </div>
</section>

<?= view('sections/render', ['sections' => $sections, 'slots' => $slots]) ?>
<?= view('partials/faq_list', ['faqs' => $faqs, 'heading' => 'Common questions']) ?>
<?= $this->endSection() ?>
