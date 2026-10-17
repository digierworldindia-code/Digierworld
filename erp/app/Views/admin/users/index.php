<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if (can('security.roles.manage')): ?><a href="<?= site_url('admin/roles') ?>" class="btn btn-outline-dark btn-sm"><i class="bi bi-shield-check"></i> Roles & permissions</a><?php endif ?>
<?php if (can('security.sessions.manage')): ?><a href="<?= site_url('admin/sessions') ?>" class="btn btn-outline-dark btn-sm"><i class="bi bi-display"></i> Active sessions</a><?php endif ?>
<?php if (can('security.users.manage')): ?><a href="<?= site_url('admin/users/new') ?>" class="btn btn-gold btn-sm"><i class="bi bi-person-plus"></i> Add user</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<form class="filter-bar row g-2" method="get">
    <div class="col-md-6"><input type="search" name="q" value="<?= esc($q) ?>" class="form-control" placeholder="Name, email or phone" aria-label="Search users"></div>
    <div class="col-md-3"><select name="status" class="form-select" data-autosubmit aria-label="Status">
        <option value="">All statuses</option><option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    </select></div>
    <div class="col-md-3"><button class="btn btn-dark w-100">Filter</button></div>
</form>
<div class="table-card"><div class="table-responsive">
<table class="table table-hover align-middle">
    <thead><tr><th>Name</th><th>Email (login)</th><th>Roles</th><th>Last seen</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
        <tr>
            <td><a href="<?= site_url('admin/users/' . $u['id']) ?>" class="fw-semibold"><?= esc($u['full_name'] ?? '(no profile)') ?></a><div class="small text-muted"><?= esc($u['designation'] ?? '') ?></div></td>
            <td class="small"><?= esc($u['email']) ?></td>
            <td><?php foreach ($roles[(int) $u['id']] ?? [] as $r): ?><span class="badge <?= $r['is_super'] ? 'bg-dark' : 'bg-light text-dark border' ?> me-1"><?= esc($r['name']) ?><?= $r['expires_at'] ? ' ⏳' : '' ?></span><?php endforeach ?><?php if ($u['is_service_account']): ?><span class="badge bg-info text-dark">Service account</span><?php endif ?></td>
            <td class="small"><?= dmy($u['last_seen_at'], true) ?></td>
            <td><?= $u['active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
        </tr>
    <?php endforeach ?>
    <?php if ($users === []): ?><tr><td colspan="5"><div class="empty-state"><i class="bi bi-people"></i>No users match.</div></td></tr><?php endif ?>
    </tbody>
</table>
</div></div>
<?= $this->endSection() ?>
