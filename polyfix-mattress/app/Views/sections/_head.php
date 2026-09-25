<?php /** Shared heading block. @var array $s */ ?>
<?php if (! empty($s['heading']) || ! empty($s['body'])): ?>
<div class="section-head">
    <?php if (! empty($s['heading'])): ?><h2><?= esc($s['heading']) ?></h2><?php endif ?>
    <?php if (! empty($s['body'])): ?><p class="lede mt-3"><?= esc($s['body']) ?></p><?php endif ?>
</div>
<?php endif ?>
