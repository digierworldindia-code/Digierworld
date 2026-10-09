<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Staff & roles', 'subtitle' => 'BearingCave employees and their platform roles.']) ?>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="bc-card mb-4"><div class="table-responsive"><table class="table table-bc table-stack mb-0"><thead><tr><th>Email</th><th>Roles</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $s): $sg = explode(',', (string) $s['groups_list']); ?><tr>
            <td data-label="Email"><a href="<?= site_url('admin/users/' . $s['id']) ?>"><?= esc($s['email']) ?></a></td><td data-label="Roles" class="small"><?= esc(implode(', ', array_map(static fn ($g) => $groups[$g]['title'] ?? $g, $sg))) ?></td><td data-label="Status"><?= $s['active'] ? status_badge('active') : status_badge('suspended', 'Inactive') ?></td>
            <td><?php if ((int) $s['id'] !== (int) auth()->id()): ?><button class="btn btn-sm btn-light" data-bs-toggle="collapse" data-bs-target="#sg<?= $s['id'] ?>" type="button">Roles</button><?php endif ?></td></tr>
            <tr class="collapse" id="sg<?= $s['id'] ?>"><td colspan="4" class="bg-soft"><form method="post" action="<?= site_url('admin/staff/' . $s['id'] . '/groups') ?>"><?= csrf_field() ?><div class="row"><?php foreach ($groups as $k => $g): ?><div class="col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="groups[]" value="<?= $k ?>" id="g<?= $s['id'] . $k ?>"<?= in_array($k, $sg, true) ? ' checked' : '' ?>><label class="form-check-label small" for="g<?= $s['id'] . $k ?>"><?= esc($g['title']) ?></label></div></div><?php endforeach ?></div><button class="btn btn-sm btn-primary mt-2" type="submit">Save roles</button></form></td></tr>
        <?php endforeach ?></tbody></table></div></div>
        <div class="bc-card"><div class="bc-card-header"><h2>Role permissions matrix</h2></div><div class="table-responsive"><table class="table table-sm small mb-0"><thead><tr><th>Permission</th><?php foreach ($groups as $k => $g): ?><th class="text-center" style="writing-mode:vertical-rl;transform:rotate(180deg);height:140px"><?= esc($g['title']) ?></th><?php endforeach ?></tr></thead><tbody>
        <?php foreach ($permissions as $perm => $desc): ?><tr><td title="<?= esc($desc, 'attr') ?>"><span class="part-no"><?= esc($perm) ?></span></td><?php foreach ($groups as $k => $g): $rules = $matrix[$k] ?? []; $has = in_array($perm, $rules, true) || in_array(explode('.', $perm)[0] . '.*', $rules, true); ?><td class="text-center"><?= $has ? '<i class="bi bi-check2 text-success" aria-label="Yes"></i>' : '' ?></td><?php endforeach ?></tr><?php endforeach ?>
        </tbody></table></div></div>
    </div>
    <div class="col-xl-4"><div class="bc-card"><div class="bc-card-header"><h2>Add staff member</h2></div><div class="bc-card-body">
        <form method="post" action="<?= site_url('admin/staff') ?>" novalidate><?= csrf_field() ?>
            <?= field('email', 'Email', null, ['type' => 'email', 'required' => true]) ?>
            <?= field('password', 'Temporary password', null, ['type' => 'password', 'required' => true, 'attrs' => 'autocomplete="new-password"']) ?>
            <span class="form-label d-block">Roles</span>
            <?php foreach ($groups as $k => $g): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="groups[]" value="<?= $k ?>" id="ng<?= $k ?>"><label class="form-check-label small" for="ng<?= $k ?>"><?= esc($g['title']) ?> <span class="text-muted">— <?= esc($g['description']) ?></span></label></div><?php endforeach ?>
            <button class="btn btn-primary w-100 mt-3" type="submit">Create staff account</button>
        </form>
    </div></div></div>
</div>
<?= $this->endSection() ?>
