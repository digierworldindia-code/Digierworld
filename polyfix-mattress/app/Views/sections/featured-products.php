<section class="section reveal">
    <div class="container">
        <?= view('sections/_head', ['s' => $s]) ?>
        <div class="row g-4">
            <?php foreach ($slots['products'] ?? [] as $product): ?>
            <div class="col-12 col-md-6 col-lg-4"><?= view('partials/product_card', ['p' => $product]) ?></div>
            <?php endforeach ?>
        </div>
    </div>
</section>
