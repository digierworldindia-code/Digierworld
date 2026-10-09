<?php
/** @var string $title */
$crumbs = $breadcrumbs ?? [];
?>
<div class="page-header">
    <div>
        <?php if ($crumbs): ?>
        <nav aria-label="Breadcrumb"><ol class="breadcrumb">
            <?php foreach ($crumbs as $label => $url): ?>
                <?php if ($url): ?><li class="breadcrumb-item"><a href="<?= site_url($url) ?>"><?= esc($label) ?></a></li>
                <?php else: ?><li class="breadcrumb-item active" aria-current="page"><?= esc($label) ?></li><?php endif ?>
            <?php endforeach ?>
        </ol></nav>
        <?php endif ?>
        <h1><?= esc($title) ?></h1>
        <?php if (! empty($subtitle)): ?><p class="subtitle"><?= esc($subtitle) ?></p><?php endif ?>
    </div>
    <?php if (! empty($actions)): ?><div class="d-flex flex-wrap gap-2"><?= $actions ?></div><?php endif ?>
</div>
