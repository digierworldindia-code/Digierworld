<section class="section<?= $alt ? ' section-sand' : '' ?> reveal">
    <div class="container">
        <?= view('sections/_head', ['s' => $s]) ?>
        <div class="row g-4">
            <?php foreach ($s['items'] ?? [] as $n => $item): ?>
            <div class="col-12 col-md-6<?= count($s['items']) > 3 ? ' col-lg-3' : ' col-lg-4' ?>">
                <div class="step h-100">
                    <div class="step__number"><?= str_pad((string) ($n + 1), 2, '0', STR_PAD_LEFT) ?></div>
                    <h3 class="h4 mt-2"><?= esc($item['title'] ?? '') ?></h3>
                    <p class="text-muted-ink small mb-0"><?= esc($item['body'] ?? '') ?></p>
                </div>
            </div>
            <?php endforeach ?>
        </div>
    </div>
</section>
