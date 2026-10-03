<?php /** @var \App\Libraries\Paging $paging */ ?>
<?php if ($paging->total > 0): ?>
<nav class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3" aria-label="Pages">
    <span class="text-muted small">
        Showing <?= number_format($paging->offset() + 1) ?>–<?= number_format(min($paging->total, $paging->offset() + $paging->perPage)) ?>
        of <?= number_format($paging->total) ?>
    </span>
    <?php if ($paging->pages() > 1): ?>
        <div class="btn-group">
            <a class="btn btn-outline-secondary<?= $paging->page <= 1 ? ' disabled' : '' ?>" href="<?= esc($paging->url(max(1, $paging->page - 1)), 'attr') ?>"><?= qms_icon('bi-chevron-left') ?> Previous</a>
            <span class="btn btn-outline-secondary disabled">Page <?= $paging->page ?> / <?= $paging->pages() ?></span>
            <a class="btn btn-outline-secondary<?= $paging->page >= $paging->pages() ? ' disabled' : '' ?>" href="<?= esc($paging->url(min($paging->pages(), $paging->page + 1)), 'attr') ?>">Next <?= qms_icon('bi-chevron-right') ?></a>
        </div>
    <?php endif ?>
</nav>
<?php endif ?>
