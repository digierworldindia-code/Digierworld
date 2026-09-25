<?php
/** @var string $active */
$nav = [
    'mattresses'  => ['mattresses', 'Mattresses'],
    'why-polyfix' => ['why-polyfix', 'Why ' . brand('shortName')],
    'warranty'    => ['warranty', 'Warranty'],
    'dealers'     => ['dealers', 'Dealers'],
    'about'       => ['about', 'About'],
    'contact'     => ['contact', 'Contact'],
];
?>
<header class="site-header">
    <nav class="navbar navbar-expand-lg container" aria-label="Primary">
        <a class="wordmark navbar-brand" href="<?= site_url('/') ?>" aria-label="<?= esc(brand('name'), 'attr') ?> home"><?= brand_wordmark() ?></a>
        <div class="d-flex align-items-center gap-2 order-lg-last ms-auto ms-lg-0">
            <a class="btn btn-outline-dark btn-sm" href="<?= site_url('warranty') ?>">Verify<span class="label-long">&nbsp;mattress</span></a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#site-nav" aria-controls="site-nav" aria-expanded="false" aria-label="Open menu">
                <span class="navbar-toggler-icon"></span>
            </button>
        </div>
        <div class="collapse navbar-collapse justify-content-center" id="site-nav">
            <ul class="navbar-nav">
                <li class="nav-item d-lg-none"><a class="nav-link" href="<?= site_url('/') ?>">Home</a></li>
                <?php foreach ($nav as $key => [$href, $label]): ?>
                <li class="nav-item">
                    <a class="nav-link<?= $active === $key ? ' active' : '' ?>" href="<?= site_url($href) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><?= esc($label) ?></a>
                </li>
                <?php endforeach ?>
            </ul>
        </div>
    </nav>
</header>
