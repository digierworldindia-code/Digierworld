<?php
/**
 * Pager under list tables. Variable: $paging (see paging()).
 */
defined('QMS') || exit;
if ($paging['total'] > 0): ?>
<nav class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3" aria-label="Pages">
    <span class="text-muted small">
        Showing <?= number_format($paging['offset'] + 1) ?>–<?= number_format(min($paging['total'], $paging['offset'] + $paging['per_page'])) ?>
        of <?= number_format($paging['total']) ?>
    </span>
    <?php $qmsPages = paging_pages($paging); if ($qmsPages > 1): ?>
        <div class="btn-group">
            <a class="btn btn-outline-secondary<?= $paging['page'] <= 1 ? ' disabled' : '' ?>" href="<?= e(paging_url(max(1, $paging['page'] - 1))) ?>"><?= qms_icon('bi-chevron-left') ?> Previous</a>
            <span class="btn btn-outline-secondary disabled">Page <?= $paging['page'] ?> / <?= $qmsPages ?></span>
            <a class="btn btn-outline-secondary<?= $paging['page'] >= $qmsPages ? ' disabled' : '' ?>" href="<?= e(paging_url(min($qmsPages, $paging['page'] + 1))) ?>">Next <?= qms_icon('bi-chevron-right') ?></a>
        </div>
    <?php endif ?>
</nav>
<?php endif;
