<?php
/**
 * @var array<string, mixed>                          $role
 * @var array<string, list<array<string, mixed>>>     $matrix
 * @var bool                                          $readOnly
 */
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="qms-page-head">
    <div><h1><?= esc($role['name']) ?></h1><p class="qms-sub"><code><?= esc($role['code']) ?></code>
        <?= (int) $role['is_super_admin'] === 1 ? ' · holds every permission (read-only)' : '' ?></p></div>
    <a class="btn btn-outline-secondary" href="<?= site_url('admin/roles') ?>"><?= qms_icon('bi-arrow-left') ?> Back</a>
</div>
<?php if ($readOnly && (int) $role['is_super_admin'] !== 1): ?>
    <div class="alert alert-warning">This is your own role. Another administrator must change it.</div>
<?php endif ?>
<form method="post" action="<?= site_url('admin/roles/' . $role['id']) ?>">
    <?= csrf_field() ?>
    <div class="card mb-3"><div class="card-body row g-3">
        <div class="col-md-5"><label class="form-label required" for="name">Name</label>
            <input class="form-control" id="name" name="name" maxlength="60" value="<?= esc($role['name'], 'attr') ?>" <?= $readOnly ? 'disabled' : 'required' ?>></div>
        <div class="col-md-7"><label class="form-label" for="description">Description</label>
            <input class="form-control" id="description" name="description" maxlength="255" value="<?= esc($role['description'] ?? '', 'attr') ?>" <?= $readOnly ? 'disabled' : '' ?>></div>
    </div></div>
    <div class="row g-3">
    <?php foreach ($matrix as $module => $permissions): ?>
        <div class="col-md-6 col-xl-4">
            <div class="card h-100"><div class="card-header text-capitalize"><?= esc($module) ?></div><div class="card-body">
                <?php foreach ($permissions as $p): ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="permissions[]" value="<?= esc($p['code'], 'attr') ?>" id="p<?= $p['id'] ?>"
                            <?= $p['granted'] ? 'checked' : '' ?> <?= $readOnly ? 'disabled' : '' ?>>
                        <label class="form-check-label" for="p<?= $p['id'] ?>"><code class="small"><?= esc($p['code']) ?></code><br><span class="small text-muted"><?= esc($p['description']) ?></span></label>
                    </div>
                <?php endforeach ?>
            </div></div>
        </div>
    <?php endforeach ?>
    </div>
    <?php if (! $readOnly): ?>
    <div class="qms-actionbar">
        <span class="qms-progress">Changes apply on the users' next click.</span>
        <button class="btn btn-primary btn-lg" type="submit" data-once><?= qms_icon('bi-check2') ?> Save permissions</button>
    </div>
    <?php endif ?>
</form>
<?php if (! $readOnly && (int) $role['is_system'] !== 1): ?>
<form method="post" action="<?= site_url('admin/roles/' . $role['id'] . '/delete') ?>" class="mt-3" data-confirm="Delete this role?">
    <?= csrf_field() ?><button class="btn btn-outline-danger" type="submit"><?= qms_icon('bi-trash') ?> Delete role</button>
</form>
<?php endif ?>
<?= $this->endSection() ?>
