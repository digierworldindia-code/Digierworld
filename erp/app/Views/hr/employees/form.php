<?= $this->extend('layouts/app') ?>
<?php
$v   = static fn (string $k, $d = '') => esc((string) (old($k) ?? ($emp[$k] ?? $d)));
$sel = static fn (string $k, $val) => (string) (old($k) ?? ($emp[$k] ?? '')) === (string) $val ? 'selected' : '';
?>
<?= $this->section('content') ?>
<form method="post" action="<?= site_url(isset($emp['id']) ? 'hr/employees/' . $emp['id'] : 'hr/employees') ?>" class="u-mxw-1040px card"><div class="card-body">
    <?= csrf_field() ?>
    <div class="card-section-title">Identity & role <?= $nextCode ? '<span class="text-muted small fw-normal">— code will be ' . esc($nextCode) . '</span>' : '<span class="mono small">' . esc($emp['employee_code']) . '</span>' ?></div>
    <div class="row g-3">
        <div class="col-md-5"><label class="form-label required" for="full_name">Full name</label><input class="form-control<?= is_invalid('full_name') ?>" id="full_name" name="full_name" value="<?= $v('full_name') ?>" maxlength="150" required autofocus><?= field_error('full_name') ?></div>
        <div class="col-md-3"><label class="form-label" for="department_id">Department</label><select class="form-select" id="department_id" name="department_id"><option value="">—</option><?php foreach ($departments as $id => $n): ?><option value="<?= $id ?>" <?= $sel('department_id', $id) ?>><?= esc($n) ?></option><?php endforeach ?></select></div>
        <div class="col-md-4"><label class="form-label" for="designation_id">Designation</label><select class="form-select" id="designation_id" name="designation_id"><option value="">—</option><?php foreach ($designations as $d): ?><option value="<?= $d['id'] ?>" <?= $sel('designation_id', $d['id']) ?>><?= esc($d['name']) ?></option><?php endforeach ?></select><div class="form-text">Add designations in Settings → Master lists.</div></div>
        <div class="col-md-4"><label class="form-label" for="reporting_manager_id">Reporting manager</label><select class="form-select<?= is_invalid('reporting_manager_id') ?>" id="reporting_manager_id" name="reporting_manager_id"><option value="">—</option><?php foreach ($managers as $m): ?><option value="<?= $m['id'] ?>" <?= $sel('reporting_manager_id', $m['id']) ?>><?= esc($m['full_name']) ?> (<?= esc($m['employee_code']) ?>)</option><?php endforeach ?></select><?= field_error('reporting_manager_id') ?></div>
        <div class="col-md-3"><label class="form-label required" for="date_of_joining">Date of joining</label><input type="date" class="form-control<?= is_invalid('date_of_joining') ?>" id="date_of_joining" name="date_of_joining" value="<?= $v('date_of_joining') ?>" required><?= field_error('date_of_joining') ?></div>
        <div class="col-md-2"><label class="form-label" for="employment_type">Type</label><select class="form-select" id="employment_type" name="employment_type"><?php foreach (['full_time' => 'Full-time', 'part_time' => 'Part-time', 'intern' => 'Intern', 'contract' => 'Contract'] as $k => $l): ?><option value="<?= $k ?>" <?= $sel('employment_type', $k) ?>><?= $l ?></option><?php endforeach ?></select></div>
        <div class="col-md-3"><label class="form-label" for="employment_status">Status</label><select class="form-select" id="employment_status" name="employment_status"><?php foreach (['probation' => 'Probation', 'active' => 'Active', 'notice' => 'Serving notice', 'exited' => 'Exited'] as $k => $l): ?><option value="<?= $k ?>" <?= $sel('employment_status', $k) ?>><?= $l ?></option><?php endforeach ?></select></div>
        <div class="col-md-3"><label class="form-label" for="work_location">Work location</label><input class="form-control" id="work_location" name="work_location" value="<?= $v('work_location') ?>" maxlength="100"></div>
        <div class="col-md-3"><label class="form-label" for="work_phone">Work phone</label><input class="form-control" id="work_phone" name="work_phone" value="<?= $v('work_phone') ?>" inputmode="tel" maxlength="20"></div>
        <div class="col-md-4"><label class="form-label" for="work_email">Work email</label><input type="email" class="form-control<?= is_invalid('work_email') ?>" id="work_email" name="work_email" value="<?= $v('work_email') ?>"><?= field_error('work_email') ?></div>
        <div class="col-md-4"><label class="form-label" for="user_id">DGR Office login</label><select class="form-select<?= is_invalid('user_id') ?>" id="user_id" name="user_id"><option value="">Not linked</option><?php foreach ($users as $uid => $n): ?><option value="<?= $uid ?>" <?= $sel('user_id', $uid) ?>><?= esc($n) ?></option><?php endforeach ?></select><?= field_error('user_id') ?><div class="form-text">Links “My Workspace” (own attendance, leave, payslips) to this employee.</div></div>
        <div class="col-md-4"><label class="form-label" for="attendance_mode">Attendance</label><select class="form-select" id="attendance_mode" name="attendance_mode"><option value="manual" <?= $sel('attendance_mode', 'manual') ?>>Marked by HR (manual)</option><option value="self_checkin" <?= $sel('attendance_mode', 'self_checkin') ?>>Self check-in / out</option></select></div>
        <div class="col-md-4 d-flex align-items-end"><div class="form-check"><input type="hidden" name="incentive_eligible" value="0"><input class="form-check-input" type="checkbox" id="incentive_eligible" name="incentive_eligible" value="1" <?= (old('incentive_eligible') ?? ($emp['incentive_eligible'] ?? 0)) ? 'checked' : '' ?>><label class="form-check-label" for="incentive_eligible">Eligible for sales incentives</label></div></div>
    </div>
    <div class="card-section-title mt-4">Personal & emergency <span class="text-muted small fw-normal">— visible only with “full employee profiles”</span></div>
    <div class="row g-3">
        <div class="col-md-3"><label class="form-label" for="personal_phone">Personal phone</label><input class="form-control" id="personal_phone" name="personal_phone" value="<?= $v('personal_phone') ?>" inputmode="tel" maxlength="20"></div>
        <div class="col-md-4"><label class="form-label" for="personal_email">Personal email</label><input type="email" class="form-control<?= is_invalid('personal_email') ?>" id="personal_email" name="personal_email" value="<?= $v('personal_email') ?>"><?= field_error('personal_email') ?></div>
        <div class="col-md-5"><label class="form-label" for="address">Address</label><input class="form-control" id="address" name="address" value="<?= $v('address') ?>" maxlength="300"></div>
        <div class="col-md-4"><label class="form-label" for="emergency_contact_name">Emergency contact</label><input class="form-control" id="emergency_contact_name" name="emergency_contact_name" value="<?= $v('emergency_contact_name') ?>"></div>
        <div class="col-md-3"><label class="form-label" for="emergency_contact_phone">Emergency phone</label><input class="form-control" id="emergency_contact_phone" name="emergency_contact_phone" value="<?= $v('emergency_contact_phone') ?>" inputmode="tel"></div>
    </div>
    <?php if (isset($emp['id'])): ?>
    <div class="card-section-title mt-4">Exit</div>
    <div class="row g-3">
        <div class="col-md-3"><label class="form-label" for="exit_date">Exit date</label><input type="date" class="form-control<?= is_invalid('exit_date') ?>" id="exit_date" name="exit_date" value="<?= $v('exit_date') ?>"><?= field_error('exit_date') ?></div>
        <div class="col-md-9"><label class="form-label" for="exit_reason">Exit reason</label><input class="form-control" id="exit_reason" name="exit_reason" value="<?= $v('exit_reason') ?>" maxlength="500"></div>
    </div>
    <?php endif ?>
    <div class="mt-4 d-flex gap-2"><button class="btn btn-primary" type="submit">Save employee</button><a class="btn btn-outline-secondary" href="<?= site_url(isset($emp['id']) ? 'hr/employees/' . $emp['id'] : 'hr/employees') ?>">Cancel</a></div>
    <p class="form-text mt-2 mb-0">Salary, bank details and private documents are added from the employee profile and need separate permissions.</p>
</div></form>
<?= $this->endSection() ?>
