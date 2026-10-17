<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if (can('hr.leave.view_all')): ?><a class="btn btn-outline-dark btn-sm" href="<?= site_url('hr/leaves/balances') ?>"><i class="bi bi-table"></i> Balances</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="row g-3">
<div class="col-xl-8">
<ul class="nav nav-tabs small mb-2">
    <?php foreach (['pending' => 'Pending (' . $pendingCount . ')', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $l): ?>
    <li class="nav-item"><a class="nav-link <?= $f['status'] === $k ? 'active' : '' ?>" href="<?= site_url('hr/leaves?' . http_build_query(array_filter(['status' => $k, 'employee' => $f['employee'] ?: null]))) ?>"><?= $l ?></a></li>
    <?php endforeach ?>
</ul>
<form class="filter-bar row g-2" method="get"><input type="hidden" name="status" value="<?= esc($f['status']) ?>">
    <div class="col-md-4"><select name="employee" class="form-select form-select-sm" data-autosubmit aria-label="Employee"><option value="">All employees</option><?php foreach ($employees as $e): ?><option value="<?= $e['id'] ?>" <?= $f['employee'] === (int) $e['id'] ? 'selected' : '' ?>><?= esc($e['full_name']) ?></option><?php endforeach ?></select></div>
    <div class="col-md-2"><select name="type" class="form-select form-select-sm" data-autosubmit aria-label="Type"><option value="">Any type</option><?php foreach ($types as $t): ?><option value="<?= $t['id'] ?>" <?= $f['type'] === (int) $t['id'] ? 'selected' : '' ?>><?= esc($t['code']) ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-2"><input type="date" name="from" value="<?= esc((string) $f['from']) ?>" class="form-control form-control-sm" aria-label="From"></div>
    <div class="col-6 col-md-2"><input type="date" name="to" value="<?= esc((string) $f['to']) ?>" class="form-control form-control-sm" aria-label="To"></div>
    <div class="col-md-2"><button class="btn btn-dark btn-sm w-100">Filter</button></div>
</form>
<div class="table-card"><div class="table-responsive"><table class="table align-middle small mb-0">
    <thead><tr><th>Employee</th><th>Leave</th><th>Dates</th><th class="num">Days</th><th>Reason</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
        <td><a href="<?= site_url('hr/employees/' . $r['employee_id']) ?>"><?= esc($r['full_name']) ?></a><div class="text-muted"><?= esc($r['employee_code']) ?></div></td>
        <td><?= esc($r['code']) ?> <span class="text-muted"><?= $r['is_paid'] ? 'paid' : 'unpaid' ?></span></td>
        <td class="text-nowrap"><?= dmy($r['from_date']) ?><?= $r['to_date'] !== $r['from_date'] ? ' – ' . dmy($r['to_date']) : '' ?><?= $r['half_day'] !== 'none' ? '<div class="text-muted">' . str_replace('_', ' ', $r['half_day']) . '</div>' : '' ?></td>
        <td class="num"><?= $r['days'] ?></td>
        <td><?= esc((string) $r['reason']) ?><?= $r['decision_note'] ? '<div class="text-muted">Note: ' . esc($r['decision_note']) . '</div>' : '' ?></td>
        <td><?= status_badge($r['status']) ?><?= $r['decided_name'] ? '<div class="text-muted">' . esc($r['decided_name']) . '</div>' : '' ?></td>
        <td class="text-end">
            <?php if ($r['status'] === 'pending' && can('hr.leave.approve') && (int) $r['emp_user_id'] !== (int) current_user_id()): ?>
            <form method="post" action="<?= site_url('hr/leaves/' . $r['id'] . '/decide') ?>" class="d-flex gap-1"><?= csrf_field() ?>
                <input class="form-control form-control-sm" name="decision_note" placeholder="Note (required to reject)" style="max-width:170px" aria-label="Decision note">
                <button class="btn btn-sm btn-outline-success" name="decision" value="approved">Approve</button><button class="btn btn-sm btn-outline-danger" name="decision" value="rejected">Reject</button></form>
            <?php elseif ($r['status'] === 'approved' && can('hr.leave.approve')): ?>
            <details><summary class="small">Cancel</summary><form method="post" action="<?= site_url('hr/leaves/' . $r['id'] . '/cancel') ?>" class="d-flex gap-1 mt-1"><?= csrf_field() ?><input class="form-control form-control-sm" name="reason" placeholder="Reason" required aria-label="Reason"><button class="btn btn-sm btn-outline-danger">Cancel leave</button></form></details>
            <?php endif ?>
        </td>
    </tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="7"><div class="empty-state"><i class="bi bi-calendar-x"></i>No leave requests here.</div></td></tr><?php endif ?>
    </tbody></table></div></div>
<?= view('partials/pager', ['total' => $total, 'page' => $page, 'perPage' => $perPage]) ?>
</div>
<div class="col-xl-4" id="apply">
<?php if (can('hr.leave.approve')): ?>
<form method="post" action="<?= site_url('hr/leaves') ?>" class="card"><div class="card-body small"><?= csrf_field() ?>
    <div class="card-section-title">Record leave for an employee</div>
    <label class="form-label required" for="lv_emp">Employee</label><select class="form-select mb-2" id="lv_emp" name="employee_id" required><option value="">—</option><?php foreach ($employees as $e): ?><option value="<?= $e['id'] ?>" <?= $f['employee'] === (int) $e['id'] ? 'selected' : '' ?>><?= esc($e['full_name']) ?></option><?php endforeach ?></select>
    <label class="form-label required" for="lv_type">Type</label><select class="form-select mb-2" id="lv_type" name="leave_type_id" required><?php foreach ($types as $t): ?><option value="<?= $t['id'] ?>"><?= esc($t['code'] . ' — ' . $t['name']) ?><?= $t['is_paid'] ? '' : ' (unpaid)' ?></option><?php endforeach ?></select>
    <div class="row g-2 mb-2"><div class="col-6"><label class="form-label required" for="lv_from">From</label><input type="date" class="form-control" id="lv_from" name="from_date" required></div><div class="col-6"><label class="form-label" for="lv_to">To</label><input type="date" class="form-control" id="lv_to" name="to_date"></div></div>
    <label class="form-label" for="lv_half">Half day</label><select class="form-select mb-2" id="lv_half" name="half_day"><option value="none">Full day(s)</option><option value="first_half">First half</option><option value="second_half">Second half</option></select>
    <label class="form-label" for="lv_reason">Reason</label><input class="form-control mb-2" id="lv_reason" name="reason" maxlength="500">
    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="approve_now" value="1" id="lv_now"><label class="form-check-label" for="lv_now">Approve immediately (not for your own leave)</label></div>
    <button class="btn btn-dark w-100" type="submit">Save leave</button>
    <div class="form-text">Holidays and weekly offs inside the range are not counted. Paid types are checked against the balance.</div>
</div></form>
<?php endif ?>
</div>
</div>
<?= $this->endSection() ?>
