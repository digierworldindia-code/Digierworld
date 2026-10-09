<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Team members', 'subtitle' => 'Give colleagues their own login with only the access they need.']) ?>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="bc-card">
            <div class="table-responsive"><table class="table table-bc table-stack mb-0">
                <thead><tr><th>Member</th><th>Role</th><th>Permissions</th><th>Status</th><?php if ($canManage): ?><th></th><?php endif ?></tr></thead>
                <tbody>
                <?php foreach ($members as $m): ?>
                    <tr>
                        <td data-label="Member"><div class="fw-semibold"><?= esc($m['email']) ?></div><div class="small text-muted"><?= esc($m['job_title'] ?? '') ?></div></td>
                        <td data-label="Role"><?= status_badge($m['member_role'] === 'owner' ? 'verified' : 'neutral', ucfirst($m['member_role'])) ?></td>
                        <td data-label="Permissions" class="small"><?= in_array($m['member_role'], ['owner', 'admin'], true) ? 'All' : esc(implode(', ', array_map(static fn ($p) => $permissions[$p] ?? $p, $m['permissions'])) ?: 'View only') ?></td>
                        <td data-label="Status"><?= status_badge($m['status']) ?></td>
                        <?php if ($canManage): ?><td>
                            <?php if ($m['member_role'] !== 'owner' && (int) $m['user_id'] !== (int) auth()->id()): ?>
                            <button class="btn btn-sm btn-light" type="button" data-bs-toggle="collapse" data-bs-target="#edit<?= $m['id'] ?>" aria-expanded="false">Edit</button>
                            <?php endif ?>
                        </td><?php endif ?>
                    </tr>
                    <?php if ($canManage && $m['member_role'] !== 'owner'): ?>
                    <tr class="collapse" id="edit<?= $m['id'] ?>"><td colspan="5" class="bg-soft">
                        <form method="post" action="<?= site_url($area . '/team/' . $m['id']) ?>" class="row g-2 align-items-end">
                            <?= csrf_field() ?>
                            <div class="col-md-3"><?= select_field('member_role', 'Role', ['admin' => 'Company admin', 'employee' => 'Employee'], $m['member_role'], ['class' => '']) ?></div>
                            <div class="col-md-3"><?= select_field('status', 'Status', ['active' => 'Active', 'disabled' => 'Disabled'], $m['status'], ['class' => '']) ?></div>
                            <div class="col-12"><div class="row">
                                <?php foreach ($permissions as $k => $l): ?><div class="col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="permissions[]" value="<?= $k ?>" id="p<?= $m['id'] . $k ?>"<?= in_array($k, $m['permissions'], true) ? ' checked' : '' ?>><label class="form-check-label small" for="p<?= $m['id'] . $k ?>"><?= esc($l) ?></label></div></div><?php endforeach ?>
                            </div></div>
                            <div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Save</button></div>
                        </form>
                    </td></tr>
                    <?php endif ?>
                <?php endforeach ?>
                </tbody>
            </table></div>
        </div>
    </div>
    <div class="col-xl-4">
        <?php if ($canManage): ?>
        <div class="bc-card"><div class="bc-card-header"><h2>Add a team member</h2></div><div class="bc-card-body">
            <form method="post" action="<?= site_url($area . '/team') ?>" novalidate>
                <?= csrf_field() ?>
                <?= field('email', 'Work email', null, ['type' => 'email', 'required' => true]) ?>
                <?= field('job_title', 'Job title', null) ?>
                <?= field('password', 'Temporary password', null, ['type' => 'password', 'required' => true, 'attrs' => 'autocomplete="new-password"', 'help' => 'Share it securely; they can change it under Account settings.']) ?>
                <?= select_field('member_role', 'Role', ['employee' => 'Employee (selected permissions)', 'admin' => 'Company admin (full access)'], 'employee') ?>
                <span class="form-label d-block">Employee permissions</span>
                <?php foreach ($permissions as $k => $l): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="permissions[]" value="<?= $k ?>" id="np<?= $k ?>"><label class="form-check-label small" for="np<?= $k ?>"><?= esc($l) ?></label></div><?php endforeach ?>
                <button class="btn btn-primary mt-3 w-100" type="submit">Add member</button>
            </form>
        </div></div>
        <?php else: ?>
            <div class="alert alert-light border small">Only your company owner or admins can manage team members.</div>
        <?php endif ?>
    </div>
</div>
<?= $this->endSection() ?>
