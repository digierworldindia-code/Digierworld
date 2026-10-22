<?= $this->extend('layouts/app') ?>
<?php $isSuper = $role && (int) $role['is_super'] === 1; ?>
<?= $this->section('content') ?>
<form method="post" action="<?= site_url($role ? 'admin/roles/' . $role['id'] : 'admin/roles') ?>">
<?= csrf_field() ?>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card"><div class="card-body">
            <div class="mb-3"><label class="form-label required" for="name">Role name</label><input class="form-control<?= is_invalid('name') ?>" id="name" name="name" value="<?= esc(old('name') ?? ($role['name'] ?? '')) ?>" required maxlength="120"><?= field_error('name') ?></div>
            <?php if (! $role): ?>
            <div class="mb-3"><label class="form-label" for="code">Code</label><input class="form-control mono<?= is_invalid('code') ?>" id="code" name="code" value="<?= esc(old('code') ?? '') ?>" placeholder="auto from name"><?= field_error('code') ?></div>
            <?php else: ?>
            <div class="mb-3 small text-muted">Code: <span class="mono"><?= esc($role['code']) ?></span> · <?= $userCount ?> user(s)</div>
            <?php endif ?>
            <div class="mb-3"><label class="form-label" for="description">Description</label><textarea class="form-control" id="description" name="description" rows="3"><?= esc(old('description') ?? ($role['description'] ?? '')) ?></textarea></div>
            <?php if (! $isSuper): $land = old('landing_url') ?? ($role['landing_url'] ?? 'auto'); ?>
            <div class="mb-3"><label class="form-label" for="landing_url">Landing page after sign-in</label>
                <select class="form-select" id="landing_url" name="landing_url">
                    <option value="auto" <?= $land === 'auto' ? 'selected' : '' ?>>Automatic (dashboard, or the first page the role may open)</option>
                    <?php foreach ($landings as $path => $opt): ?><option value="<?= esc($path === '' ? '/' : $path) ?>" <?= $land !== 'auto' && (string) $land === $path ? 'selected' : '' ?>><?= esc($opt['label']) ?></option><?php endforeach ?>
                </select>
                <div class="form-text">Users with this role open this page after signing in (only if their permissions allow it; otherwise the automatic choice is used).</div></div>
            <?php else: ?>
            <div class="mb-3 small text-muted">Landing page: Owner dashboard.</div>
            <?php endif ?>
            <?php if ($role && ! $isSuper): ?>
            <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= $role['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="is_active">Active</label></div>
            <?php endif ?>
            <?php if ($isSuper): ?>
                <div class="alert alert-dark small mb-0">Owner / Super Admin holds every permission and cannot be deactivated or deleted. At least one active Super Admin user must always exist.</div>
            <?php else: ?>
            <div class="alert alert-warning small d-none" data-danger-warning><i class="bi bi-shield-exclamation"></i> <span data-danger-count>0</span> sensitive permission(s) selected. Adding new sensitive permissions requires your password:
                <input type="password" name="admin_password" class="form-control form-control-sm mt-2<?= is_invalid('admin_password') ?>" autocomplete="current-password" aria-label="Your password"><?= field_error('admin_password') ?></div>
            <?php endif ?>
            <button class="btn btn-primary w-100"><?= $role ? 'Save role' : 'Create role' ?></button>
            <?php if ($role && ! $isSuper && $userCount === 0): ?>
                <button form="delRole" class="btn btn-outline-danger w-100 mt-2">Delete role</button>
            <?php endif ?>
        </div></div>
    </div>
    <div class="col-lg-8">
        <?php if (! $isSuper): ?>
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2"><div class="card-section-title mb-0">Permissions</div><input type="search" class="u-mxw-220px form-control form-control-sm" placeholder="Filter…" data-perm-filter aria-label="Filter permissions"></div>
            <p class="small text-muted">Separate rights for view-own / view-assigned / view-team / view-all, create, edit, approve, finalize, print, download, export and delete. ⚠ marks sensitive permissions.</p>
            <?php foreach ($modules as $mod => $def): ?>
                <div class="border rounded mb-2">
                    <div class="d-flex justify-content-between align-items-center px-3 py-2 bg-body-tertiary"><strong class="small"><?= esc($def['label']) ?></strong><button type="button" class="btn btn-sm btn-link p-0" data-module-toggle="<?= esc($mod) ?>">toggle all</button></div>
                    <div class="px-3 py-2">
                    <?php foreach ($def['perms'] as $key => $meta): $danger = ! empty($meta[1]['danger']); ?>
                        <div class="form-check" data-perm-row="<?= esc($key . ' ' . $meta[0], 'attr') ?>">
                            <input class="form-check-input" type="checkbox" name="perms[]" value="<?= esc($key) ?>" id="p_<?= esc(str_replace('.', '_', $key)) ?>" data-module="<?= esc($mod) ?>" data-danger="<?= $danger ? 1 : 0 ?>" <?= isset($selected[$key]) ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="p_<?= esc(str_replace('.', '_', $key)) ?>"><code><?= esc($key) ?></code><?= $danger ? ' <span class="perm-danger">⚠</span>' : '' ?> — <?= esc($meta[0]) ?></label>
                        </div>
                    <?php endforeach ?>
                    </div>
                </div>
            <?php endforeach ?>
        </div></div>
        <?php endif ?>
    </div>
</div>
</form>
<?php if ($role && ! $isSuper && $userCount === 0): ?>
<form id="delRole" method="post" action="<?= site_url('admin/roles/' . $role['id'] . '/delete') ?>" data-confirm="Delete this role permanently?" data-confirm-danger><?= csrf_field() ?></form>
<?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="<?= asset('js/admin.js') ?>"></script><?= $this->endSection() ?>
