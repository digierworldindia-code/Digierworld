<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<form class="filter-bar d-flex gap-2" method="get" action="<?= site_url('search') ?>">
    <input type="search" name="q" value="<?= esc($q) ?>" class="form-control" placeholder="Type at least 2 characters" autofocus aria-label="Search">
    <button class="btn btn-dark">Search</button>
</form>
<?php if ($q !== '' && $results === []): ?>
    <div class="table-card"><div class="empty-state"><i class="bi bi-search"></i>No results you have access to for “<?= esc($q) ?>”.</div></div>
<?php elseif ($results !== []): ?>
<div class="table-card">
    <table class="table table-hover align-middle">
        <thead><tr><th>Type</th><th>Record</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach ($results as $r): ?>
            <tr><td><span class="badge bg-light text-dark"><?= esc($r['type']) ?></span></td><td><a href="<?= esc($r['url']) ?>"><?= esc($r['label']) ?></a></td><td class="small text-muted"><?= esc($r['sub']) ?></td></tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php endif ?>
<?= $this->endSection() ?>
