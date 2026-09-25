<?php
/**
 * Heading block at the top of an inner page, from the CMS page's `hero`.
 *
 * @var array  $hero  eyebrow, heading, body, optional image
 * @var string $h1    fallback heading
 */
$heading = $hero['heading'] ?? $h1 ?? '';
$image   = $hero['image'] ?? null;
?>
<section class="section-tight">
    <div class="container">
        <div class="row g-5 align-items-center">
            <div class="<?= $image ? 'col-lg-6' : 'col-lg-9' ?>">
                <?php if (! empty($hero['eyebrow'])): ?><p class="eyebrow"><?= esc($hero['eyebrow']) ?></p><?php endif ?>
                <h1><?= esc($heading) ?></h1>
                <?php if (! empty($hero['body'])): ?><p class="lede mt-3"><?= esc($hero['body']) ?></p><?php endif ?>
            </div>
            <?php if ($image): ?>
            <div class="col-lg-6 hero__media">
                <img class="img-fluid" src="<?= esc(base_url(ltrim($image['src'], '/')), 'attr') ?>" alt="<?= esc($image['alt'] ?? '', 'attr') ?>"
                     width="<?= (int) ($image['width'] ?? 1600) ?>" height="<?= (int) ($image['height'] ?? 1000) ?>" fetchpriority="high">
            </div>
            <?php endif ?>
        </div>
    </div>
</section>
