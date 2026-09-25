<section class="section-tight reveal">
    <div class="container container-narrow prose">
        <?php if (! empty($s['heading'])): ?><h2><?= esc($s['heading']) ?></h2><?php endif ?>
        <?php foreach (preg_split('/\n{2,}/', (string) ($s['body'] ?? '')) as $para): ?>
            <?php if (trim($para) !== ''): ?><p class="text-muted-ink"><?= nl2br(esc(trim($para))) ?></p><?php endif ?>
        <?php endforeach ?>
    </div>
</section>
