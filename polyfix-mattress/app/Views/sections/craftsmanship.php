<section class="section section-sand reveal">
    <div class="container">
        <?= view('sections/_head', ['s' => $s]) ?>
        <div class="row g-4">
            <?php foreach ($s['steps'] ?? [] as $n => $step): ?>
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="step">
                    <div class="step__number"><?= str_pad((string) ($n + 1), 2, '0', STR_PAD_LEFT) ?></div>
                    <h3 class="h4 mt-2"><?= esc($step['title'] ?? '') ?></h3>
                    <p class="text-muted-ink small mb-0"><?= esc($step['body'] ?? '') ?></p>
                </div>
            </div>
            <?php endforeach ?>
        </div>
    </div>
</section>
