<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Users']) ?>
<form class="row g-2 mb-3" method="get"><div class="col-md-5"><label class="visually-hidden" for="uq">Search</label><input id="uq" class="form-control form-control-sm" name="q" value="<?= esc((string) service('request')->getGet('q'), 'attr') ?>" placeholder="Email or company"></div><div class="col-md-4"><label class="visually-hidden" for="ug">Role</label><select id="ug" class="form-select form-select-sm" name="group"><option value="">All roles</option><?php foreach ($groups as $k => $g): ?><option value="<?= $k ?>"<?= service('request')->getGet('group') === $k ? ' selected' : '' ?>><?= esc($g['title']) ?></option><?php endforeach ?></select></div><div class="col-md-3"><button class="btn btn-sm btn-light w-100">Filter</button></div></form>
<div class="bc-card"><div class="table-responsive"><table class="table table-bc table-stack mb-0">
    <thead><tr><th>Email</th><th>Roles</th><th>Company</th><th>Last active</th><th>Status</th><th></th></tr></thead>
    <tbody><?php foreach ($rows as $u): ?><tr>
        <td data-label="Email"><a href="<?= site_url('admin/users/' . $u['id']) ?>"><?= esc($u['email']) ?></a></td><td data-label="Roles" class="small"><?= esc(str_replace('_', ' ', (string) $u['groups_list'])) ?></td>
        <td data-label="Company" class="small"><?= $u['company_id'] ? '<a href="' . site_url('admin/companies/' . $u['company_id']) . '">' . esc($u['legal_name']) . '</a> · ' . esc($u['member_role']) : '—' ?></td>
        <td data-label="Last active" class="small"><?= fdt($u['last_active']) ?></td><td data-label="Status"><?= $u['active'] ? status_badge('active') : status_badge('suspended', 'Inactive') ?></td>
        <td><?php if (auth()->user()->can('users.manage') && (int) $u['id'] !== (int) auth()->id()): ?><?= post_button('admin/users/' . $u['id'] . '/toggle', $u['active'] ? 'Deactivate' : 'Activate', 'btn btn-sm btn-light', $u['active'] ? 'Deactivate this login?' : null) ?><?php endif ?></td>
    </tr><?php endforeach ?></tbody>
</table></div><?= pagination_links($page, $pages, array_filter(service('request')->getGet() ?? [])) ?></div>
<?= $this->endSection() ?>
