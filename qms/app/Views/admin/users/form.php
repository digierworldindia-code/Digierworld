<?php
/**
 * @var array<string, mixed>|null $user
 * @var array<string, string>     $errors
 */
$isNew = $user === null;
$val   = static fn (string $k) => old($k) ?? ($user[$k] ?? '');
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="qms-page-head">
    <div><h1><?= $isNew ? 'New user' : 'User ' . esc($user['username']) ?></h1>
        <p class="qms-sub"><?= $isNew ? 'A one-time password is generated; the user must change it at first login.' : 'Changing the role ends the user\'s open sessions.' ?></p></div>
    <a class="btn btn-outline-secondary" href="<?= site_url('admin/users') ?>"><?= qms_icon('bi-arrow-left') ?> Back</a>
</div>
<div class="row g-3">
<div class="col-lg-7">
<div class="card"><div class="card-body">
<form method="post" action="<?= site_url($isNew ? 'admin/users' : 'admin/users/' . $user['id']) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label required" for="username">Username</label>
        <input class="form-control form-control-lg<?= field_invalid($errors, 'username') ?>" id="username" name="username" value="<?= esc($val('username'), 'attr') ?>"
               maxlength="50" autocomplete="off" <?= $isNew ? 'required' : 'readonly' ?>>
        <?= field_error($errors, 'username') ?>
        <?php if (! $isNew): ?><div class="form-text">Usernames cannot be changed.</div><?php endif ?>
    </div>
    <div class="mb-3">
        <label class="form-label" for="email">E-mail</label>
        <input class="form-control<?= field_invalid($errors, 'email') ?>" id="email" name="email" type="email" maxlength="150" value="<?= esc($val('email'), 'attr') ?>">
        <?= field_error($errors, 'email') ?>
    </div>
    <div class="mb-3">
        <label class="form-label required" for="role_id">Role</label>
        <select class="form-select form-select-lg<?= field_invalid($errors, 'role_id') ?>" id="role_id" name="role_id" required>
            <option value="">Choose…</option>
            <?php foreach ($roles as $role): ?>
                <option value="<?= $role['id'] ?>"<?= (string) $val('role_id') === (string) $role['id'] ? ' selected' : '' ?>><?= esc($role['name']) ?></option>
            <?php endforeach ?>
        </select>
        <?= field_error($errors, 'role_id') ?>
    </div>
    <div class="mb-4">
        <label class="form-label" for="employee_id">Employee</label>
        <select class="form-select<?= field_invalid($errors, 'employee_id') ?>" id="employee_id" name="employee_id">
            <option value="">— not linked —</option>
            <?php foreach ($employees as $emp): ?>
                <option value="<?= $emp['id'] ?>"<?= (string) $val('employee_id') === (string) $emp['id'] ? ' selected' : '' ?>><?= esc($emp['employee_code'] . ' · ' . $emp['full_name']) ?></option>
            <?php endforeach ?>
        </select>
        <?= field_error($errors, 'employee_id') ?>
        <div class="form-text">Signatures and printed reports show the linked employee's name and code.</div>
    </div>
    <button class="btn btn-primary btn-lg" type="submit" data-once><?= qms_icon('bi-check2') ?> <?= $isNew ? 'Create user' : 'Save' ?></button>
</form>
</div></div>
</div>
<?php if (! $isNew): ?>
<div class="col-lg-5">
<div class="card"><div class="card-header">Account</div><div class="card-body">
    <dl class="qms-dl mb-3">
        <dt>Status</dt><dd><?= esc($user['status']) ?></dd>
        <dt>Last login</dt><dd><?= esc(plant_dt($user['last_login_at'])) ?: 'Never' ?></dd>
        <dt>Password changed</dt><dd><?= esc(plant_dt($user['password_changed_at'])) ?: '—' ?></dd>
        <dt>Locked</dt><dd><?= $locked ? 'Until ' . esc(plant_dt($user['locked_until'])) : 'No' ?></dd>
    </dl>
    <div class="d-grid gap-2">
        <form method="post" action="<?= site_url('admin/users/' . $user['id'] . '/reset-password') ?>" data-confirm="Generate a new one-time password for this user?">
            <?= csrf_field() ?><button class="btn btn-outline-primary w-100" type="submit"><?= qms_icon('bi-key') ?> Reset password</button>
        </form>
        <?php if ($locked): ?>
        <form method="post" action="<?= site_url('admin/users/' . $user['id'] . '/unlock') ?>">
            <?= csrf_field() ?><button class="btn btn-outline-primary w-100" type="submit"><?= qms_icon('bi-unlock') ?> Unlock account</button>
        </form>
        <?php endif ?>
        <form method="post" action="<?= site_url('admin/users/' . $user['id'] . '/status') ?>" data-confirm="<?= $user['status'] === 'ACTIVE' ? 'Disable this account? Open sessions end immediately.' : 'Enable this account?' ?>">
            <?= csrf_field() ?><button class="btn <?= $user['status'] === 'ACTIVE' ? 'btn-outline-danger' : 'btn-outline-success' ?> w-100" type="submit">
                <?= qms_icon($user['status'] === 'ACTIVE' ? 'bi-person-x' : 'bi-person-check') ?> <?= $user['status'] === 'ACTIVE' ? 'Disable account' : 'Enable account' ?></button>
        </form>
    </div>
</div></div>
</div>
<?php endif ?>
</div>
<?= $this->endSection() ?>
