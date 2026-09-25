<?php
$icons = ['comfort' => 'bi-cloud', 'materials' => 'bi-layers', 'support' => 'bi-grid-3x3-gap', 'warranty' => 'bi-shield-check'];
?>
<section class="section section-sand reveal" aria-label="<?= esc($s['heading'] ?? 'Benefits', 'attr') ?>">
    <div class="container">
        <?= view('sections/_head', ['s' => $s]) ?>
        <div class="row g-4">
            <?php foreach ($s['items'] ?? [] as $item): ?>
            <div class="col-12 col-sm-6 col-lg-3 benefit">
                <span class="benefit__icon"><i class="bi <?= $icons[$item['icon'] ?? ''] ?? 'bi-check2' ?>" aria-hidden="true"></i></span>
                <h3><?= esc($item['title'] ?? '') ?></h3>
                <p><?= esc($item['body'] ?? '') ?></p>
            </div>
            <?php endforeach ?>
        </div>
        <p class="mt-5"><a class="btn btn-outline-dark" href="<?= site_url('why-polyfix') ?>">See what sits behind each of these</a></p>
    </div>
</section>
