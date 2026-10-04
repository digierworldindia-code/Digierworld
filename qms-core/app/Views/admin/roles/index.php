<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="qms-page-head">
    <div><h1>Roles &amp; permissions</h1><p class="qms-sub">Each user has one role. Open a role to edit its permission matrix.</p></div>
</div>
<div class="row g-3">
<div class="col-xl-8">
<div class="card"><div class="qms-table-wrap">
<table class="table table-hover">
    <thead><tr><th>Role</th><th>Code</th><th class="text-end">Users</th><th class="text-end">Permissions</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($roles as $role): ?>
        <tr>
            <td><strong><?= esc($role['name']) ?></strong><div class="small text-muted"><?= esc($role['description'] ?? '') ?></div></td>
            <td><code><?= esc($role['code']) ?></code><?php if ((int) $role['is_system'] === 1): ?> <span class="qms-badge qms-badge--muted">system</span><?php endif ?></td>
            <td class="text-end num"><?= (int) $role['user_count'] ?></td>
            <td class="text-end num"><?= (int) $role['is_super_admin'] === 1 ? 'All' : (int) $role['permission_count'] ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= site_url('admin/roles/' . $role['id']) ?>"><?= qms_icon('bi-grid-3x3-gap') ?> Permissions</a></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div></div>
</div>
<div class="col-xl-4">
<div class="card"><div class="card-header">New custom role</div><div class="card-body">
<form method="post" action="<?= site_url('admin/roles') ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3"><label class="form-label required" for="code">Code</label>
        <input class="form-control<?= field_invalid($errors, 'code') ?>" id="code" name="code" maxlength="30" placeholder="e.g. LINE_INSPECTOR" value="<?= esc(old('code') ?? '', 'attr') ?>" required>
        <?= field_error($errors, 'code') ?></div>
    <div class="mb-3"><label class="form-label required" for="name">Name</label>
        <input class="form-control<?= field_invalid($errors, 'name') ?>" id="name" name="name" maxlength="60" value="<?= esc(old('name') ?? '', 'attr') ?>" required>
        <?= field_error($errors, 'name') ?></div>
    <div class="mb-3"><label class="form-label" for="description">Description</label>
        <input class="form-control" id="description" name="description" maxlength="255" value="<?= esc(old('description') ?? '', 'attr') ?>"></div>
    <button class="btn btn-primary" type="submit" data-once><?= qms_icon('bi-plus-lg') ?> Create role</button>
</form>
</div></div>
</div>
</div>
<?= $this->endSection() ?>
