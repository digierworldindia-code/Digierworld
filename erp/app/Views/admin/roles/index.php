<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<a href="<?= site_url('admin/roles/matrix') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-grid-3x3"></i> Full matrix</a>
<a href="<?= site_url('admin/roles/new') ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> New role</a>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<p class="small text-muted">Role names are labels only. What a person can see and do is decided by the permission keys in their roles (plus per-user overrides), enforced on every page, API, export and file download.</p>
<div class="table-card"><div class="table-responsive">
<table class="table table-hover align-middle">
    <thead><tr><th>Role</th><th>Description</th><th class="num">Users</th><th class="num">Permissions</th><th class="num">Sensitive</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($roles as $r): ?>
        <tr>
            <td><a href="<?= site_url('admin/roles/' . $r['id']) ?>" class="fw-semibold"><?= esc($r['name']) ?></a><div class="small text-muted mono"><?= esc($r['code']) ?></div></td>
            <td class="small"><?= esc($r['description']) ?></td>
            <td class="num"><?= (int) $r['users'] ?></td>
            <td class="num"><?= $r['is_super'] ? 'All (' . $total . ')' : (int) $r['perms'] ?></td>
            <td class="num"><?= $r['is_super'] ? '—' : (int) $r['danger'] ?></td>
            <td><?= $r['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-neutral">Inactive</span>' ?></td>
            <td class="text-end text-nowrap">
                <?php if (! $r['is_super']): ?>
                <form method="post" action="<?= site_url('admin/roles/' . $r['id'] . '/clone') ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary">Clone</button></form>
                <?php endif ?>
            </td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div></div>
<?= $this->endSection() ?>
