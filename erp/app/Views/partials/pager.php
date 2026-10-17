<?php
/** @var int $total @var int $page @var int $perPage */
$pages = max(1, (int) ceil($total / max(1, $perPage)));
if ($total === 0) {
    return;
}
$query = service('request')->getGet();
$link  = static function (int $p) use ($query): string {
    $query['page'] = $p;

    return current_url() . '?' . http_build_query($query);
};
$from = ($page - 1) * $perPage + 1;
$to   = min($total, $page * $perPage);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2 small">
    <div class="text-muted">Showing <?= $from ?>–<?= $to ?> of <?= number_format($total) ?></div>
    <?php if ($pages > 1): ?>
    <nav aria-label="Pagination"><ul class="pagination pagination-sm mb-0">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= esc($link(max(1, $page - 1))) ?>">‹ Prev</a></li>
        <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= esc($link($p)) ?>"><?= $p ?></a></li>
        <?php endfor ?>
        <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= esc($link(min($pages, $page + 1))) ?>">Next ›</a></li>
    </ul></nav>
    <?php endif ?>
</div>
