<section class="section section-ink reveal">
    <div class="container">
        <div class="row align-items-center g-4">
            <div class="col-lg-7">
                <h2><?= esc($s['heading'] ?? '') ?></h2>
                <?php if (! empty($s['body'])): ?><p class="lede mt-3"><?= esc($s['body']) ?></p><?php endif ?>
            </div>
            <div class="col-lg-5 d-flex flex-wrap gap-3 justify-content-lg-end">
                <?php if (! empty($s['primaryCta']['href'])): ?><a class="btn btn-on-ink" href="<?= site_url(ltrim($s['primaryCta']['href'], '/')) ?>"><?= esc($s['primaryCta']['label']) ?></a><?php endif ?>
                <?php if (! empty($s['secondaryCta']['href'])): ?><a class="btn btn-outline-light" href="<?= site_url(ltrim($s['secondaryCta']['href'], '/')) ?>"><?= esc($s['secondaryCta']['label']) ?></a><?php endif ?>
            </div>
        </div>
    </div>
</section>
