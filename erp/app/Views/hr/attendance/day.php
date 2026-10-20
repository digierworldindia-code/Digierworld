<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if (can('hr.attendance.view_all')): ?><a class="btn btn-outline-secondary btn-sm" href="<?= site_url('hr/attendance/register?month=' . substr($date, 0, 7)) ?>"><i class="bi bi-grid-3x3"></i> Month register</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<form class="filter-bar row g-2" method="get">
    <div class="col-6 col-md-3"><input type="date" name="date" value="<?= esc($date) ?>" max="<?= date('Y-m-d') ?>" class="form-control form-control-sm" data-autosubmit aria-label="Date"></div>
    <div class="col-6 col-md-3"><select name="department" class="form-select form-select-sm" data-autosubmit aria-label="Department"><option value="">All departments</option><?php foreach ($departments as $id => $n): ?><option value="<?= $id ?>" <?= (int) $department === (int) $id ? 'selected' : '' ?>><?= esc($n) ?></option><?php endforeach ?></select></div>
    <div class="col-md-6 small align-self-center">
        <a href="<?= site_url('hr/attendance?date=' . date('Y-m-d', strtotime($date . ' -1 day'))) ?>">‹ Previous day</a>
        <?php if ($date < date('Y-m-d')): ?> · <a href="<?= site_url('hr/attendance?date=' . date('Y-m-d', strtotime($date . ' +1 day'))) ?>">Next day ›</a><?php endif ?>
        <?php if ($dayKind): ?> · <span class="badge badge-neutral"><?= $dayKind === 'holiday' ? 'Holiday: ' . esc((string) $holidayName) : 'Weekly off' ?></span><?php endif ?>
    </div>
</form>
<?php if ($locked): ?><div class="alert alert-secondary small"><i class="bi bi-lock-fill"></i> Payroll for this month is finalized and locked. Attendance cannot be changed — record corrections as payroll adjustments.</div><?php endif ?>
<form method="post" action="<?= site_url('hr/attendance') ?>">
<?= csrf_field() ?><input type="hidden" name="date" value="<?= esc($date) ?>">
<div class="table-card"><div class="table-responsive">
<table class="table align-middle small mb-0">
    <thead><tr><th>Employee</th><th>Status</th><th>In</th><th>Out</th><th>OT (min)</th><th>Remarks</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $m = $r['mark']; $cur = $m['status'] ?? $r['suggest']; $ro = $locked || ! can('hr.attendance.mark') || ($m !== null && $m['leave_request_id']); ?>
    <tr class="<?= $m === null ? 'table-warning-subtle' : '' ?>">
        <td><a href="<?= site_url('hr/employees/' . $r['id']) ?>"><?= esc($r['full_name']) ?></a><div class="text-muted"><?= esc($r['employee_code']) ?> · <?= esc((string) $r['department']) ?></div></td>
        <td><div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="Status for <?= esc($r['full_name']) ?>">
            <?php foreach ($statuses as $k => $l): if ($k === 'leave' && ! ($m && $m['status'] === 'leave')) { continue; } ?>
            <input type="radio" class="btn-check" name="att[<?= $r['id'] ?>][status]" id="s<?= $r['id'] . $k ?>" value="<?= $k ?>" <?= $cur === $k ? 'checked' : '' ?> <?= $ro ? 'disabled' : '' ?>>
            <label class="btn btn-outline-secondary" for="s<?= $r['id'] . $k ?>"><?= $l ?></label>
            <?php endforeach ?></div>
            <?php if ($ro && $m !== null): ?><input type="hidden" name="att[<?= $r['id'] ?>][status]" value=""><?php endif ?></td>
        <td><input class="u-w-84px form-control form-control-sm" name="att[<?= $r['id'] ?>][check_in]" value="<?= $m && $m['check_in_at'] ? date('H:i', strtotime($m['check_in_at'])) : '' ?>" placeholder="09:30" <?= $ro ? 'disabled' : '' ?> aria-label="Check-in"></td>
        <td><input class="u-w-84px form-control form-control-sm" name="att[<?= $r['id'] ?>][check_out]" value="<?= $m && $m['check_out_at'] ? date('H:i', strtotime($m['check_out_at'])) : '' ?>" placeholder="18:30" <?= $ro ? 'disabled' : '' ?> aria-label="Check-out"></td>
        <td><input class="u-w-70px form-control form-control-sm" name="att[<?= $r['id'] ?>][overtime_minutes]" value="<?= (int) ($m['overtime_minutes'] ?? 0) ?: '' ?>" inputmode="numeric" <?= $ro ? 'disabled' : '' ?> aria-label="Overtime minutes"></td>
        <td><input class="form-control form-control-sm" name="att[<?= $r['id'] ?>][remarks]" value="<?= esc((string) ($m['remarks'] ?? '')) ?>" maxlength="255" <?= $ro ? 'disabled' : '' ?> aria-label="Remarks"></td>
        <td class="text-nowrap"><?= $m === null ? '<span class="badge badge-warning">not saved</span>' : ($m['corrected'] ? '<span class="badge badge-info" title="' . esc((string) $m['correction_reason']) . '">corrected</span>' : '') ?><?= $m && $m['source'] === 'self' ? ' <span class="badge badge-neutral">self</span>' : '' ?><?= $m && $m['leave_request_id'] ? ' <span class="badge badge-gold">approved leave</span>' : '' ?></td>
    </tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="7"><div class="empty-state"><i class="bi bi-calendar-check"></i>No employees were employed on this date.</div></td></tr><?php endif ?>
    </tbody>
</table>
</div></div>
<?php if (! $locked && can('hr.attendance.mark') && $rows !== []): ?>
<div class="sticky-actions d-flex flex-wrap gap-2 align-items-center mt-3">
    <?php if (can('hr.attendance.correct')): ?><input class="u-mxw-420px form-control form-control-sm" name="reason" placeholder="Reason — required only when changing an already-saved mark" aria-label="Correction reason"><?php endif ?>
    <button class="btn btn-primary" type="submit">Save attendance</button>
    <span class="small text-muted">Unsaved rows are pre-filled (Present / holiday / week off). Changing a saved mark is a correction and is audited.</span>
</div>
<?php endif ?>
</form>
<?= $this->endSection() ?>
