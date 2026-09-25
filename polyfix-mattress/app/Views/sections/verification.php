<section class="section reveal">
    <div class="container">
        <div class="row align-items-center g-4">
            <div class="col-lg-8"><?= view('sections/_head', ['s' => $s]) ?></div>
            <?php if (! empty($s['cta']['href'])): ?>
            <div class="col-lg-4 text-lg-end">
                <a class="btn btn-primary" href="<?= site_url(ltrim($s['cta']['href'], '/')) ?>"><?= esc($s['cta']['label'] ?? 'Learn more') ?></a>
            </div>
            <?php endif ?>
        </div>
    </div>
</section>
