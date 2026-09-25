<?php
/**
 * One product in a grid.
 *
 * @var array $p  a row from Catalogue::products()
 */
$img = $p['image'] ?? null;
?>
<article class="card product-card h-100">
    <a class="card-link-wrap" href="<?= site_url('mattresses/' . $p['slug']) ?>" aria-label="<?= esc($p['name'], 'attr') ?>">
        <div class="product-card__media">
            <?php if ($img !== null): ?>
            <img src="<?= esc(base_url(ltrim($img['src'], '/')), 'attr') ?>" alt="<?= esc($img['alt'] ?? $p['name'], 'attr') ?>"
                 width="<?= (int) ($img['width'] ?? 1200) ?>" height="<?= (int) ($img['height'] ?? 900) ?>" loading="lazy" decoding="async">
            <?php endif ?>
        </div>
    </a>
    <div class="card-body d-flex flex-column gap-2">
        <p class="eyebrow mb-0"><?= esc($p['category']) ?></p>
        <h3 class="h4 mb-0"><a class="stretched-link text-reset text-decoration-none" href="<?= site_url('mattresses/' . $p['slug']) ?>"><?= esc($p['name']) ?></a></h3>
        <?php if (! empty($p['tagline'])): ?><p class="text-muted-ink small mb-0"><?= esc($p['tagline']) ?></p><?php endif ?>
        <div class="product-card__meta mt-auto d-flex justify-content-between align-items-end pt-2">
            <span class="small"><?= esc($p['comfort_level']) ?> · <?= (int) $p['warranty_years'] ?>-year warranty</span>
            <?php if ($p['price_from'] !== null): ?>
            <span class="product-card__price text-end"><span class="small d-block text-muted-ink">from</span><?= inr((float) $p['price_from']) ?></span>
            <?php endif ?>
        </div>
    </div>
</article>
