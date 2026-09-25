<?= $this->extend('layouts/site') ?>
<?= $this->section('content') ?>
<?php /** @var array<string, list<array>> $grouped @var array $trail */ ?>
<?= view('partials/breadcrumbs', ['trail' => $trail]) ?>
<section class="section-tight">
    <div class="container">
        <div class="section-head">
            <p class="eyebrow">The range</p>
            <h1><?= esc(brand('shortName')) ?> mattresses</h1>
            <p class="lede mt-3">Five constructions covering firm through medium-soft. Every model lists its materials, densities and firmness rating, so you can compare them on the same terms rather than on adjectives.</p>
        </div>
        <?php if ($grouped === []): ?>
        <div class="alert alert-secondary">The catalogue is being updated. Please check back shortly, or <a href="<?= site_url('contact') ?>">contact us</a> and we will help.</div>
        <?php endif ?>
        <?php foreach ($grouped as $category => $items): ?>
        <section class="mb-5" aria-labelledby="cat-<?= esc(url_title($category, '-', true), 'attr') ?>">
            <h2 class="h3 mb-4" id="cat-<?= esc(url_title($category, '-', true), 'attr') ?>"><?= esc($category) ?></h2>
            <div class="row g-4">
                <?php foreach ($items as $product): ?>
                <div class="col-12 col-md-6 col-lg-4"><?= view('partials/product_card', ['p' => $product]) ?></div>
                <?php endforeach ?>
            </div>
        </section>
        <?php endforeach ?>
    </div>
</section>

<section class="section section-sand">
    <div class="container">
        <div class="section-head">
            <h2>Choosing a firmness</h2>
            <p class="lede mt-3">Firmness is personal. As a starting point, match it to how you sleep.</p>
        </div>
        <div class="row g-4">
            <div class="col-md-4"><div class="card h-100"><div class="card-body">
                <h3 class="h4">Side sleepers</h3>
                <p class="text-muted-ink mb-0">The shoulder and hip carry most of the load, so a surface with more give usually feels better. Look at medium-soft to medium: Serenity or Latex Natura.</p>
            </div></div></div>
            <div class="col-md-4"><div class="card h-100"><div class="card-body">
                <h3 class="h4">Back sleepers</h3>
                <p class="text-muted-ink mb-0">The lumbar curve needs filling without the hips dropping. Medium-firm tends to suit: Aurea Hybrid, or Dual Comfort on its firmer face.</p>
            </div></div></div>
            <div class="col-md-4"><div class="card h-100"><div class="card-body">
                <h3 class="h4">Front sleepers</h3>
                <p class="text-muted-ink mb-0">A softer mattress lets the hips sink and the lower back arch. Firmer is usually better: Orthocore Support.</p>
            </div></div></div>
        </div>
        <p class="text-muted-ink mt-5 container-narrow px-0 ms-0">These are general guides, not medical advice. If you have a specific back or joint condition, speak to your doctor, and try the mattress at a dealer before you decide.</p>
        <p class="mt-4"><a class="btn btn-primary" href="<?= site_url('dealers') ?>">Find a dealer to try them</a></p>
    </div>
</section>
<?= $this->endSection() ?>
