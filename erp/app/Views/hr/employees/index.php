<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if (can('hr.employee.manage')): ?><a href="<?= site_url('hr/employees/new') ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Add employee</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<form class="filter-bar row g-2" method="get">
    <div class="col-md-4"><input type="search" name="q" value="<?= esc($f['q']) ?>" class="form-control form-control-sm" placeholder="Name, code, work email or phone" aria-label="Search"></div>
    <div class="col-6 col-md-3"><select name="department" class="form-select form-select-sm" data-autosubmit aria-label="Department"><option value="">All departments</option><?php foreach ($departments as $id => $n): ?><option value="<?= $id ?>" <?= $f['department'] === (int) $id ? 'selected' : '' ?>><?= esc($n) ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-3"><select name="status" class="form-select form-select-sm" data-autosubmit aria-label="Status"><?php foreach (['current' => 'Current staff', 'probation' => 'On probation', 'active' => 'Active', 'notice' => 'Serving notice', 'exited' => 'Exited', 'all' => 'Everyone'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></div>
    <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Filter</button></div>
</form>
<div class="table-card"><div class="table-responsive">
<table class="table table-hover align-middle small">
    <thead><tr><th>Code</th><th>Name</th><th>Department / designation</th><th>Reports to</th><th>Work contact</th><?= can('hr.employee.view_all') ? '<th>Joined</th>' : '' ?><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
        <td class="mono"><?= esc($r['employee_code']) ?></td>
        <td><a class="fw-semibold" href="<?= site_url('hr/employees/' . $r['id']) ?>"><?= esc($r['full_name']) ?></a></td>
        <td><?= esc((string) $r['department']) ?><div class="text-muted"><?= esc((string) $r['designation']) ?></div></td>
        <td><?= esc((string) $r['manager']) ?: '—' ?></td>
        <td><?= esc((string) $r['work_phone']) ?><div class="text-muted"><?= esc((string) $r['work_email']) ?></div></td>
        <?= array_key_exists('date_of_joining', $r) ? '<td>' . dmy($r['date_of_joining']) . '</td>' : '' ?>
        <td><?= status_badge($r['employment_status']) ?></td>
    </tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="7"><div class="empty-state"><i class="bi bi-people"></i>No employees match. <?= can('hr.employee.manage') ? 'Add one, or convert a joined candidate from Applicants.' : '' ?></div></td></tr><?php endif ?>
    </tbody>
</table>
</div></div>
<?= view('partials/pager', ['total' => $total, 'page' => $page, 'perPage' => $perPage]) ?>
<?= $this->endSection() ?>
