<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<form method="post" action="<?= site_url('admin/users') ?>" autocomplete="off">
<?= csrf_field() ?>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card"><div class="card-body">
            <div class="card-section-title">Person</div>
            <div class="mb-3"><label class="form-label required" for="full_name">Full name</label>
                <input class="form-control<?= is_invalid('full_name') ?>" id="full_name" name="full_name" value="<?= esc(old('full_name') ?? '') ?>" required maxlength="150"></div>
            <div class="mb-3"><label class="form-label required" for="email">Email (used to sign in)</label>
                <input type="email" class="form-control<?= is_invalid('email') ?>" id="email" name="email" value="<?= esc(old('email') ?? '') ?>" required><?= field_error('email') ?></div>
            <div class="row g-2">
                <div class="col-md-6 mb-3"><label class="form-label" for="phone">Phone</label><input class="form-control" id="phone" name="phone" value="<?= esc(old('phone') ?? '') ?>" inputmode="tel"></div>
                <div class="col-md-6 mb-3"><label class="form-label" for="designation">Designation</label><input class="form-control" id="designation" name="designation" value="<?= esc(old('designation') ?? '') ?>"></div>
            </div>
            <div class="mb-3"><label class="form-label" for="manager_user_id">Reports to</label>
                <select class="form-select" id="manager_user_id" name="manager_user_id"><option value="">— none —</option>
                    <?php foreach ($managers as $m): ?><option value="<?= $m['id'] ?>" <?= (string) old('manager_user_id') === (string) $m['id'] ? 'selected' : '' ?>><?= esc($m['name']) ?></option><?php endforeach ?>
                </select>
                <div class="form-text">Drives "team" scope: managers with team permissions see their reports' leads and stats.</div></div>
            <div class="mb-3"><label class="form-label" for="password">Temporary password</label>
                <input type="text" class="form-control<?= is_invalid('password') ?>" id="password" name="password" placeholder="Leave blank to generate a strong one" autocomplete="new-password"><?= field_error('password') ?>
                <div class="form-text">The user must change it at first sign-in.</div></div>
        </div></div>
    </div>
    <div class="col-lg-6">
        <div class="card"><div class="card-body">
            <div class="card-section-title">Roles (one or more)</div>
            <?= field_error('roles') ?>
            <?php foreach ($roles as $r): if ($r['is_super'] && ! $canAssignSuper) { continue; } ?>
                <div class="form-check border-bottom py-2">
                    <input class="form-check-input" type="checkbox" name="roles[]" value="<?= $r['id'] ?>" id="role<?= $r['id'] ?>" <?= in_array((string) $r['id'], (array) old('roles'), true) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="role<?= $r['id'] ?>"><strong><?= esc($r['name']) ?></strong><?= $r['is_super'] ? ' <span class="badge bg-dark">full access</span>' : '' ?><br><span class="small text-muted"><?= esc($r['description']) ?></span></label>
                </div>
            <?php endforeach ?>
            <div class="mt-3"><label class="form-label" for="role_expires_at">Role access expires on (optional)</label>
                <input type="date" class="form-control" id="role_expires_at" name="role_expires_at" value="<?= esc(old('role_expires_at') ?? '') ?>">
                <div class="form-text">Use for auditors and temporary staff — access ends automatically.</div></div>
            <div class="alert alert-warning small mt-3 mb-0">
                <i class="bi bi-shield-exclamation"></i> Roles with sensitive permissions (finance totals, salaries, bank, exports, finalization) require you to re-enter your password.
                <input type="password" class="form-control form-control-sm mt-2<?= is_invalid('admin_password') ?>" name="admin_password" placeholder="Your password (if required)" autocomplete="current-password" aria-label="Your password"><?= field_error('admin_password') ?>
            </div>
        </div></div>
    </div>
</div>
<div class="sticky-actions"><a href="<?= site_url('admin/users') ?>" class="btn btn-outline-secondary">Cancel</a><button class="btn btn-dark">Create user</button></div>
</form>
<?= $this->endSection() ?>
