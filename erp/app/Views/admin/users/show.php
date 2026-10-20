<?= $this->extend('layouts/app') ?>
<?php $canManage = can('security.users.manage'); $canRoles = can('security.roles.manage'); $grantedCount = count(array_filter($summary, static fn ($s) => $s['granted'])); ?>
<?= $this->section('pageActions') ?>
<?php if ($canManage): ?>
    <form method="post" action="<?= site_url('admin/users/' . $user['user_id'] . '/toggle') ?>" data-confirm="<?= $user['active'] ? 'Deactivate this user? They will be signed out everywhere.' : 'Activate this user?' ?>" <?= $user['active'] ? 'data-confirm-danger' : '' ?>><?= csrf_field() ?>
        <button class="btn btn-sm <?= $user['active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>"><?= $user['active'] ? 'Deactivate' : 'Activate' ?></button></form>
<?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php if (! $user['active']): ?><div class="alert alert-secondary">This account is inactive and cannot sign in.</div><?php endif ?>
<?php if ($isSuperUser): ?><div class="alert alert-dark small"><i class="bi bi-shield-fill-check"></i> Owner / Super Admin — holds every permission. Privileged actions are recorded in the audit log.</div><?php endif ?>
<?php if (count($dangerous) >= 15 && ! $isSuperUser): ?><div class="alert alert-warning small"><i class="bi bi-exclamation-triangle"></i> Unusually broad privileges: this user holds <?= count($dangerous) ?> sensitive permissions. Review whether all are needed.</div><?php endif ?>
<?php foreach ($sod as $w): ?><div class="alert alert-warning small py-2"><i class="bi bi-diagram-3"></i> Separation of duties: <?= esc($w) ?>.</div><?php endforeach ?>

<div class="row g-3">
<div class="col-xl-5">
    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">Profile</div>
        <form method="post" action="<?= site_url('admin/users/' . $user['user_id']) ?>">
            <?= csrf_field() ?>
            <div class="mb-2 small text-muted">Login: <strong><?= esc($user['email']) ?></strong> · created <?= dmy($user['created_at']) ?> · last seen <?= dmy($user['last_seen_at'], true) ?></div>
            <div class="mb-2"><label class="form-label" for="full_name">Full name</label><input class="form-control" id="full_name" name="full_name" value="<?= esc($user['full_name']) ?>" <?= $canManage ? '' : 'disabled' ?>></div>
            <div class="row g-2">
                <div class="col-6 mb-2"><label class="form-label" for="phone">Phone</label><input class="form-control" id="phone" name="phone" value="<?= esc($user['phone'] ?? '') ?>" <?= $canManage ? '' : 'disabled' ?>></div>
                <div class="col-6 mb-2"><label class="form-label" for="designation">Designation</label><input class="form-control" id="designation" name="designation" value="<?= esc($user['designation'] ?? '') ?>" <?= $canManage ? '' : 'disabled' ?>></div>
            </div>
            <div class="mb-2"><label class="form-label" for="manager_user_id">Reports to</label>
                <select class="form-select" id="manager_user_id" name="manager_user_id" <?= $canManage ? '' : 'disabled' ?>><option value="">— none —</option>
                    <?php foreach ($managers as $m): ?><option value="<?= $m['id'] ?>" <?= (int) ($user['manager_user_id'] ?? 0) === $m['id'] ? 'selected' : '' ?>><?= esc($m['name']) ?></option><?php endforeach ?>
                </select></div>
            <?php if ($canManage): ?><button class="btn btn-primary btn-sm">Save profile</button><?php endif ?>
        </form>
    </div></div>

    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">Roles</div>
        <form method="post" action="<?= site_url('admin/users/' . $user['user_id'] . '/roles') ?>">
            <?= csrf_field() ?>
            <?php foreach ($allRoles as $r): if ($r['is_super'] && ! $actorIsSuper && ! isset($userRoles[$r['id']])) { continue; } $has = isset($userRoles[$r['id']]); ?>
                <div class="d-flex align-items-center gap-2 border-bottom py-2">
                    <div class="form-check flex-grow-1 mb-0">
                        <input class="form-check-input" type="checkbox" name="roles[]" value="<?= $r['id'] ?>" id="ur<?= $r['id'] ?>" <?= $has ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>>
                        <label class="form-check-label small" for="ur<?= $r['id'] ?>"><strong><?= esc($r['name']) ?></strong></label>
                    </div>
                    <input type="date" class="u-mxw-150px form-control form-control-sm" name="expires_<?= $r['id'] ?>" value="<?= $has && $userRoles[$r['id']]['expires_at'] ? esc(substr($userRoles[$r['id']]['expires_at'], 0, 10)) : '' ?>" aria-label="Expiry for <?= esc($r['name']) ?>" title="Optional expiry" <?= $canManage ? '' : 'disabled' ?>>
                </div>
            <?php endforeach ?>
            <?php if ($canManage): ?>
            <input type="password" class="form-control form-control-sm mt-2<?= is_invalid('admin_password') ?>" name="admin_password" placeholder="Your password (required when adding sensitive roles)" autocomplete="current-password" aria-label="Your password"><?= field_error('admin_password') ?>
            <button class="btn btn-primary btn-sm mt-2">Save roles</button>
            <?php endif ?>
        </form>
        <?php if ($lastChange): ?><div class="small text-muted mt-2">Last permission change: <?= dmy($lastChange['occurred_at'], true) ?> (<?= esc($lastChange['summary']) ?>)</div><?php endif ?>
    </div></div>

    <?php if ($canRoles): ?>
    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">Per-user overrides <span class="text-muted text-lowercase fw-normal">(deny always wins)</span></div>
        <?php foreach ($overrides as $o): ?>
            <div class="d-flex justify-content-between align-items-start border-bottom py-2 small">
                <div><span class="badge <?= $o['effect'] === 'deny' ? 'badge-danger' : 'badge-success' ?>"><?= esc($o['effect']) ?></span> <code><?= esc($o['perm_key']) ?></code><div class="text-muted"><?= esc($o['reason']) ?><?= $o['expires_at'] ? ' · until ' . dmy($o['expires_at']) : '' ?></div></div>
                <form method="post" action="<?= site_url('admin/users/' . $user['user_id'] . '/overrides/' . $o['id'] . '/delete') ?>" data-confirm="Remove this override?"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0">Remove</button></form>
            </div>
        <?php endforeach ?>
        <form method="post" action="<?= site_url('admin/users/' . $user['user_id'] . '/overrides') ?>" class="mt-2">
            <?= csrf_field() ?>
            <div class="row g-2">
                <div class="col-8"><select class="form-select form-select-sm" name="perm_key" required aria-label="Permission">
                    <option value="">Choose permission…</option>
                    <?php foreach ($modules as $mod => $def): ?><optgroup label="<?= esc($def['label']) ?>">
                        <?php foreach ($def['perms'] as $k => $m): ?><option value="<?= esc($k) ?>"><?= esc($k) ?><?= ! empty($m[1]['danger']) ? ' ⚠' : '' ?></option><?php endforeach ?>
                    </optgroup><?php endforeach ?>
                </select></div>
                <div class="col-4"><select class="form-select form-select-sm" name="effect" aria-label="Effect"><option value="grant">Grant</option><option value="deny">Deny</option></select></div>
                <div class="col-8"><input class="form-control form-control-sm" name="reason" placeholder="Reason (required)" required aria-label="Reason"></div>
                <div class="col-4"><input type="date" class="form-control form-control-sm" name="expires_at" aria-label="Expires" title="Optional expiry"></div>
                <div class="col-8"><input type="password" class="form-control form-control-sm" name="admin_password" placeholder="Your password (for sensitive grants)" autocomplete="current-password" aria-label="Your password"></div>
                <div class="col-4"><button class="btn btn-sm btn-primary w-100">Add</button></div>
            </div>
        </form>
    </div></div>

    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">Employee allowlist (payroll/HR scope)</div>
        <p class="small text-muted">If set, HR/payroll permissions for this user apply ONLY to the selected employees. Leave empty for no extra restriction.</p>
        <form method="post" action="<?= site_url('admin/users/' . $user['user_id'] . '/scopes') ?>">
            <?= csrf_field() ?>
            <select class="form-select form-select-sm" name="employee_ids[]" multiple size="6" aria-label="Employees">
                <?php foreach ($employees as $e): ?><option value="<?= $e['id'] ?>" <?= in_array((int) $e['id'], $scopes, true) ? 'selected' : '' ?>><?= esc($e['employee_code'] . ' — ' . $e['full_name']) ?></option><?php endforeach ?>
            </select>
            <button class="btn btn-sm btn-primary mt-2">Save allowlist</button>
        </form>
    </div></div>
    <?php endif ?>

    <?php if ($canManage): ?>
    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">Password</div>
        <form method="post" action="<?= site_url('admin/users/' . $user['user_id'] . '/password') ?>" data-confirm="Reset this user's password and sign them out everywhere?" class="d-flex gap-2">
            <?= csrf_field() ?>
            <input type="text" class="form-control form-control-sm" name="password" placeholder="New temporary password (blank = generate)" autocomplete="new-password" aria-label="New password">
            <button class="btn btn-sm btn-outline-secondary text-nowrap">Reset</button>
        </form>
    </div></div>
    <?php endif ?>
</div>

<div class="col-xl-7">
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center"><div class="card-section-title mb-0">Effective permissions (<?= $grantedCount ?>)</div><input type="search" class="u-mxw-220px form-control form-control-sm" placeholder="Filter…" data-perm-filter aria-label="Filter permissions"></div>
        <p class="small text-muted mt-1">What this person can actually do right now, and why. ⚠ = sensitive.</p>
        <div class="u-mxh-640px table-responsive">
        <table class="table table-sm small align-middle" data-perm-table>
            <thead><tr><th>Permission</th><th class="text-center">Allowed</th><th>Source</th></tr></thead>
            <tbody>
            <?php foreach ($modules as $mod => $def): ?>
                <tr><th colspan="3"><?= esc($def['label']) ?></th></tr>
                <?php foreach ($def['perms'] as $k => $m): $s = $summary[$k]; ?>
                    <tr data-perm-row="<?= esc($k . ' ' . $m[0], 'attr') ?>">
                        <td><code><?= esc($k) ?></code><?= $flat[$k]['danger'] ? ' <span class="perm-danger" title="Sensitive">⚠</span>' : '' ?><div class="text-muted"><?= esc($m[0]) ?></div></td>
                        <td class="text-center"><?= $s['granted'] ? '<i class="bi bi-check-circle-fill text-success" aria-label="allowed"></i>' : '<i class="bi bi-dash-circle text-muted" aria-label="not allowed"></i>' ?></td>
                        <td class="text-muted"><?= esc(implode(', ', $s['sources'])) ?><?= $s['denied_by_override'] ? ' <span class="badge badge-danger">denied by override</span>' : '' ?></td>
                    </tr>
                <?php endforeach ?>
            <?php endforeach ?>
            </tbody>
        </table>
        </div>
    </div></div>

    <div class="table-card">
        <div class="p-3 border-bottom d-flex justify-content-between align-items-center"><strong>Sessions</strong>
            <?php if (can('security.sessions.manage')): ?>
            <form method="post" action="<?= site_url('admin/users/' . $user['user_id'] . '/sessions/revoke') ?>" data-confirm="Sign this user out of all sessions now?" data-confirm-danger><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger">Revoke all sessions</button></form>
            <?php endif ?>
        </div>
        <table class="table table-sm small mb-0"><thead><tr><th>Device</th><th>IP</th><th>Last activity</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($sessions as $s): ?>
            <tr><td><?= esc(mb_strimwidth((string) $s['user_agent'], 0, 50, '…')) ?></td><td class="mono"><?= esc($s['ip_address']) ?></td><td><?= dmy($s['last_activity_at'], true) ?></td><td><?= $s['revoked_at'] ? '<span class="badge badge-neutral">revoked</span>' : '<span class="badge badge-success">active</span>' ?></td></tr>
        <?php endforeach ?>
        <?php if ($sessions === []): ?><tr><td colspan="4" class="text-muted">No sessions recorded.</td></tr><?php endif ?>
        </tbody></table>
    </div>
</div>
</div>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="<?= asset('js/admin.js') ?>"></script><?= $this->endSection() ?>
