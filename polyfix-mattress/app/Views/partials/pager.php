<?php /** @var array{total:int, page:int, pages:int, per:int} $p */ ?>
<?php if ($p['total'] > 0): ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 py-2 border-top small text-muted">
    <span><?= number_format(($p['page'] - 1) * $p['per'] + 1) ?>–<?= number_format(min($p['total'], $p['page'] * $p['per'])) ?> of <?= number_format($p['total']) ?></span>
    <?php if ($p['pages'] > 1): ?>
    <nav aria-label="Pages"><ul class="pagination pagination-sm mb-0">
        <li class="page-item<?= $p['page'] <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= esc(query_url(['page' => $p['page'] - 1]), 'attr') ?>">Previous</a></li>
        <li class="page-item disabled"><span class="page-link">Page <?= $p['page'] ?> of <?= $p['pages'] ?></span></li>
        <li class="page-item<?= $p['page'] >= $p['pages'] ? ' disabled' : '' ?>"><a class="page-link" href="<?= esc(query_url(['page' => $p['page'] + 1]), 'attr') ?>">Next</a></li>
    </ul></nav>
    <?php endif ?>
</div>
<?php endif ?>
