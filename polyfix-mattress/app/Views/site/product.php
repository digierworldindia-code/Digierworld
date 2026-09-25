<?= $this->extend('layouts/site') ?>
<?= $this->section('content') ?>
<?php
/** @var array $p @var list<array> $related @var array $trail @var float|null $lowest */
$img = static fn (array $i, string $extra = ''): string => '<img class="img-fluid ' . $extra . '" src="' . esc(base_url(ltrim($i['src'], '/')), 'attr')
    . '" alt="' . esc($i['alt'] ?? '', 'attr') . '" width="' . (int) ($i['width'] ?? 1200) . '" height="' . (int) ($i['height'] ?? 900) . '"';
?>
<?= view('partials/breadcrumbs', ['trail' => $trail]) ?>

<section class="section-tight">
    <div class="container">
        <div class="row g-5 align-items-center">
            <div class="col-lg-6">
                <p class="eyebrow"><?= esc($p['category']) ?></p>
                <h1><?= esc($p['name']) ?></h1>
                <?php if (! empty($p['tagline'])): ?><p class="lede mt-3"><?= esc($p['tagline']) ?></p><?php endif ?>
                <p><?= esc($p['short_description']) ?></p>
                <div class="d-flex flex-wrap gap-2 mt-4">
                    <span class="badge badge-neutral"><?= esc($p['comfort_level']) ?></span>
                    <span class="badge badge-neutral">Firmness <?= (int) $p['firmness_score'] ?>/10</span>
                    <span class="badge badge-positive"><?= (int) $p['warranty_years'] ?> year warranty</span>
                </div>
                <?php if ($lowest !== null): ?>
                <p class="mt-4"><span class="text-muted-ink small">Recommended retail from </span><strong class="fs-3 display-font"><?= inr($lowest) ?></strong></p>
                <?php endif ?>
                <div class="d-flex flex-wrap gap-3 mt-4">
                    <a class="btn btn-primary" href="<?= site_url('dealers') ?>">Find a dealer</a>
                    <a class="btn btn-outline-dark" href="<?= site_url('warranty') ?>">Verify a mattress</a>
                </div>
            </div>
            <div class="col-lg-6 hero__media">
                <?php if (isset($p['images'][0])): ?><?= $img($p['images'][0]) ?> fetchpriority="high"><?php endif ?>
            </div>
        </div>
    </div>
</section>

<?php if ($p['highlights'] !== []): ?>
<section class="section-tight section-sand reveal">
    <div class="container">
        <div class="section-head">
            <h2><?= esc($p['headline'] ?: 'Why this one') ?></h2>
            <?php if (! empty($p['subheadline'])): ?><p class="lede mt-3"><?= esc($p['subheadline']) ?></p><?php endif ?>
        </div>
        <div class="row g-4">
            <?php foreach ($p['highlights'] as $h): ?>
            <div class="col-md-4"><div class="card h-100"><div class="card-body">
                <h3 class="h4"><?= esc($h['title'] ?? '') ?></h3>
                <p class="text-muted-ink mb-0"><?= esc($h['body'] ?? '') ?></p>
            </div></div></div>
            <?php endforeach ?>
        </div>
    </div>
</section>
<?php endif ?>

<section class="section reveal">
    <div class="container">
        <div class="row g-5">
            <div class="col-lg-6">
                <h2>Construction</h2>
                <div class="prose mt-3"><p><?= esc($p['description']) ?></p></div>
                <?php foreach (['materials' => 'Materials', 'features' => 'Features'] as $list => $label): ?>
                    <?php if ($p[$list] !== []): ?>
                    <h3 class="h4 mt-4"><?= $label ?></h3>
                    <ul class="text-muted-ink d-grid gap-2">
                        <?php foreach ($p[$list] as $line): ?><li><?= esc($line) ?></li><?php endforeach ?>
                    </ul>
                    <?php endif ?>
                <?php endforeach ?>
            </div>
            <div class="col-lg-6">
                <?php if (isset($p['images'][1])): ?><?= $img($p['images'][1], 'rounded-4 border') ?> loading="lazy"><?php endif ?>
                <table class="spec-table mt-4">
                    <caption class="visually-hidden">Specifications for <?= esc($p['name']) ?></caption>
                    <tbody>
                    <?php foreach ($p['specs'] as $k => $v): ?>
                        <tr><th scope="row"><?= esc((string) $k) ?></th><td><?= esc(is_scalar($v) ? (string) $v : json_encode($v)) ?></td></tr>
                    <?php endforeach ?>
                        <tr><th scope="row">Warranty</th><td><?= (int) $p['warranty_years'] ?> years from the date of sale</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<?php if ($p['sizes'] !== []): ?>
<section class="section-tight section-sand reveal" id="sizes">
    <div class="container">
        <div class="section-head">
            <h2>Sizes and prices</h2>
            <p class="lede mt-3">Recommended retail prices. Your dealer sets the final price and handles delivery.</p>
        </div>
        <div class="table-responsive">
            <table class="spec-table bg-white rounded-3">
                <caption class="visually-hidden">Available sizes for <?= esc($p['name']) ?></caption>
                <thead><tr><th scope="col" class="w-40">Size</th><th scope="col">Dimensions (inches)</th><th scope="col">Recommended retail</th></tr></thead>
                <tbody>
                <?php foreach ($p['sizes'] as $s): ?>
                    <tr>
                        <th scope="row"><?= esc($s['size_label']) ?></th>
                        <td><?= esc(rtrim(rtrim((string) $s['length_in'], '0'), '.')) ?> × <?= esc(rtrim(rtrim((string) $s['width_in'], '0'), '.')) ?> × <?= esc(rtrim(rtrim((string) $s['height_in'], '0'), '.')) ?></td>
                        <td><?= inr((float) $s['mrp']) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php endif ?>

<?php if (! empty($p['care_instructions'])): ?>
<section class="section-tight reveal">
    <div class="container container-narrow">
        <h2>Care</h2>
        <p class="text-muted-ink mt-3"><?= esc($p['care_instructions']) ?></p>
    </div>
</section>
<?php endif ?>

<?= view('partials/faq_list', ['faqs' => $p['faqs'], 'heading' => $p['name'] . ' questions']) ?>

<section class="section section-ink reveal">
    <div class="container text-center">
        <h2>Try it before you decide</h2>
        <p class="lede mx-auto mt-3"><?= esc(brand('shortName')) ?> mattresses are sold through appointed dealers who hold stock and handle warranty support. Find one near you and lie on it for ten minutes.</p>
        <div class="d-flex flex-wrap gap-3 justify-content-center mt-4">
            <a class="btn btn-on-ink" href="<?= site_url('dealers') ?>">Find a dealer</a>
            <a class="btn btn-outline-light" href="<?= site_url('contact') ?>">Ask a question</a>
        </div>
    </div>
</section>

<?php if ($related !== []): ?>
<section class="section-tight">
    <div class="container">
        <div class="section-head"><h2>Other <?= esc(brand('shortName')) ?> mattresses</h2></div>
        <div class="row g-4">
            <?php foreach ($related as $r): ?>
            <div class="col-md-4">
                <article class="card h-100 position-relative"><div class="card-body">
                    <p class="eyebrow"><?= esc($r['category']) ?></p>
                    <h3 class="h4"><a class="stretched-link text-reset text-decoration-none" href="<?= site_url('mattresses/' . $r['slug']) ?>"><?= esc($r['name']) ?></a></h3>
                    <p class="text-muted-ink small mb-0"><?= esc($r['short_description']) ?></p>
                </div></article>
            </div>
            <?php endforeach ?>
        </div>
    </div>
</section>
<?php endif ?>
<?= $this->endSection() ?>
