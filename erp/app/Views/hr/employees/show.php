<?= $this->extend('layouts/app') ?>
<?php $id = (int) $emp['id']; ?>
<?= $this->section('pageActions') ?>
<?php if (can('hr.employee.manage')): ?><a href="<?= site_url('hr/employees/' . $id . '/edit') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil"></i> Edit</a><?php endif ?>
<?php if (can('hr.leave.approve') && $full): ?><a href="<?= site_url('hr/leaves?employee=' . $id . '#apply') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-calendar-plus"></i> Record leave</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="row g-3">
<div class="col-xl-4">
    <div class="card mb-3"><div class="card-body small">
        <div class="d-flex justify-content-between align-items-start"><div><div class="h5 mb-0"><?= esc($emp['full_name']) ?></div><div class="text-muted mono"><?= esc($emp['employee_code']) ?></div></div><?= status_badge($emp['employment_status']) ?></div>
        <dl class="row mb-0 mt-3">
            <dt class="col-5">Department</dt><dd class="col-7"><?= esc((string) $department) ?: '—' ?></dd>
            <dt class="col-5">Designation</dt><dd class="col-7"><?= esc((string) $designation) ?: '—' ?></dd>
            <dt class="col-5">Reports to</dt><dd class="col-7"><?= $manager ? '<a href="' . site_url('hr/employees/' . $manager['id']) . '">' . esc($manager['full_name']) . '</a>' : '—' ?></dd>
            <dt class="col-5">Work phone</dt><dd class="col-7"><?= esc((string) $emp['work_phone']) ?: '—' ?></dd>
            <dt class="col-5">Work email</dt><dd class="col-7 text-break"><?= esc((string) $emp['work_email']) ?: '—' ?></dd>
            <dt class="col-5">Location</dt><dd class="col-7"><?= esc((string) $emp['work_location']) ?: '—' ?></dd>
            <dt class="col-5">Login</dt><dd class="col-7"><?= $login ? esc($login) : '<span class="text-muted">not linked</span>' ?></dd>
            <?php if ($full): ?>
            <dt class="col-5">Joined</dt><dd class="col-7"><?= dmy($emp['date_of_joining']) ?> · <?= esc(ucwords(str_replace('_', ' ', $emp['employment_type']))) ?></dd>
            <dt class="col-5">Attendance</dt><dd class="col-7"><?= $emp['attendance_mode'] === 'self_checkin' ? 'Self check-in' : 'Marked by HR' ?></dd>
            <dt class="col-5">Incentives</dt><dd class="col-7"><?= $emp['incentive_eligible'] ? 'Eligible' : 'Not eligible' ?></dd>
            <dt class="col-5">Personal</dt><dd class="col-7"><?= esc((string) $emp['personal_phone']) ?> <?= esc((string) $emp['personal_email']) ?></dd>
            <dt class="col-5">Address</dt><dd class="col-7"><?= esc((string) $emp['address']) ?: '—' ?></dd>
            <dt class="col-5">Emergency</dt><dd class="col-7"><?= esc((string) $emp['emergency_contact_name']) ?> <?= esc((string) $emp['emergency_contact_phone']) ?></dd>
            <?php if ($emp['exit_date']): ?><dt class="col-5">Exit</dt><dd class="col-7"><?= dmy($emp['exit_date']) ?> — <?= esc((string) $emp['exit_reason']) ?></dd><?php endif ?>
            <?php endif ?>
        </dl>
        <?php if (! empty($reports)): ?><div class="mt-2"><span class="text-muted">Team:</span> <?= implode(', ', array_map(static fn ($r) => '<a href="' . site_url('hr/employees/' . $r['id']) . '">' . esc($r['full_name']) . '</a>', $reports)) ?></div><?php endif ?>
        <?php if (! $full): ?><div class="alert alert-secondary border small mt-3 mb-0">You see the directory view. Personal details, salary and documents need additional HR permissions.</div><?php endif ?>
    </div></div>

    <?php if (can('hr.employee.bank_view')): ?>
    <div class="card mb-3" id="bank"><div class="card-body small">
        <div class="card-section-title">Bank details <i class="bi bi-lock-fill text-muted" title="Restricted"></i></div>
        <?php if ($bank): ?>
        <dl class="row mb-2">
            <dt class="col-5">Bank</dt><dd class="col-7"><?= esc((string) $bank['bank_name']) ?></dd>
            <dt class="col-5">Holder</dt><dd class="col-7"><?= esc((string) $bank['account_holder']) ?></dd>
            <dt class="col-5">Account</dt><dd class="col-7"><span class="mono masked" data-reveal-target="eacct"><?= mask_tail((string) $bank['account_last4']) ?></span> <?php if ($bank['account_last4']): ?><button type="button" class="btn btn-link btn-sm p-0 ms-1" data-reveal="<?= site_url('hr/employees/' . $id . '/bank/reveal') ?>" data-field="account_number" data-target="eacct">reveal</button><?php endif ?></dd>
            <dt class="col-5">IFSC</dt><dd class="col-7 mono"><?= esc((string) $bank['ifsc']) ?></dd>
            <dt class="col-5">UPI</dt><dd class="col-7"><?= esc((string) $bank['upi_id']) ?></dd>
        </dl>
        <?php else: ?><p class="text-muted">No bank details recorded.</p><?php endif ?>
        <?php if (can('hr.employee.manage')): ?>
        <details><summary class="small">Update bank details</summary>
        <form method="post" action="<?= site_url('hr/employees/' . $id . '/bank') ?>" class="mt-2"><?= csrf_field() ?>
            <input class="form-control form-control-sm mb-2" name="bank_name" placeholder="Bank name" value="<?= esc((string) ($bank['bank_name'] ?? '')) ?>" aria-label="Bank name">
            <input class="form-control form-control-sm mb-2" name="account_holder" placeholder="Account holder" value="<?= esc((string) ($bank['account_holder'] ?? '')) ?>" aria-label="Account holder">
            <input class="form-control form-control-sm mb-2<?= is_invalid('account_number') ?>" name="account_number" placeholder="Account number (leave blank to keep)" inputmode="numeric" autocomplete="off" aria-label="Account number"><?= field_error('account_number') ?>
            <input class="form-control form-control-sm mb-2<?= is_invalid('ifsc') ?>" name="ifsc" placeholder="IFSC" value="<?= esc((string) ($bank['ifsc'] ?? '')) ?>" aria-label="IFSC"><?= field_error('ifsc') ?>
            <input class="form-control form-control-sm mb-2<?= is_invalid('upi_id') ?>" name="upi_id" placeholder="UPI ID (optional)" value="<?= esc((string) ($bank['upi_id'] ?? '')) ?>" aria-label="UPI ID"><?= field_error('upi_id') ?>
            <button class="btn btn-sm btn-primary" type="submit">Save bank details</button>
        </form></details>
        <?php endif ?>
    </div></div>
    <?php endif ?>

    <?php if (can('hr.employee.private_docs')): ?>
    <div class="card mb-3" id="docs"><div class="card-body small">
        <div class="card-section-title">Private documents <i class="bi bi-lock-fill text-muted"></i></div>
        <?php foreach ($docs as $d): ?>
        <div class="d-flex justify-content-between border-bottom py-1"><span><?= esc(\App\Services\Hr\EmployeeService::DOC_KINDS[$d['doc_kind']] ?? $d['doc_kind']) ?><?= $d['label'] ? ' — ' . esc($d['label']) : '' ?></span><a href="<?= site_url('files/' . $d['stored_file_id']) ?>"><?= esc(mb_strimwidth($d['original_name'], 0, 28, '…')) ?></a></div>
        <?php endforeach ?>
        <?php if ($docs === []): ?><p class="text-muted">None uploaded.</p><?php endif ?>
        <form method="post" action="<?= site_url('hr/employees/' . $id . '/documents') ?>" enctype="multipart/form-data" class="mt-2"><?= csrf_field() ?>
            <div class="input-group input-group-sm mb-2"><select class="form-select" name="doc_kind" aria-label="Document type"><?php foreach (\App\Services\Hr\EmployeeService::DOC_KINDS as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach ?></select><input class="form-control" name="label" placeholder="Label" aria-label="Label"></div>
            <div class="input-group input-group-sm"><input type="file" class="form-control" name="document" accept=".pdf,.png,.jpg,.jpeg,.docx" required aria-label="File"><button class="btn btn-primary" type="submit">Upload</button></div>
            <div class="form-text">PDF, image or DOCX up to 8 MB. Stored outside the web root.</div>
        </form>
    </div></div>
    <?php endif ?>
</div>

<div class="col-xl-8">
    <?php if ($full): ?>
    <div class="row g-3 mb-3">
        <?php foreach ([['Present', $attendance['present']], ['Paid leave', $attendance['paid_leave']], ['Unpaid / LWP', $attendance['unpaid_leave'] + $attendance['absent']], ['Not marked', $attendance['unmarked']]] as [$l, $n]): ?>
        <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label"><?= $l ?> · <?= date('M Y', strtotime($month . '-01')) ?></div><div class="kpi-value"><?= rtrim(rtrim(number_format((float) $n, 1), '0'), '.') ?></div></div></div>
        <?php endforeach ?>
    </div>
    <div class="table-card mb-3"><div class="card-header d-flex justify-content-between"><strong>Leave balances</strong><a class="small" href="<?= site_url('hr/leaves?employee=' . $id) ?>">Requests</a></div><div class="table-responsive"><table class="table small mb-0">
        <thead><tr><th>Type</th><th class="num">Entitled</th><th class="num">Carried</th><th class="num">Adjusted</th><th class="num">Used</th><th class="num">Pending</th><th class="num">Available</th></tr></thead><tbody>
        <?php foreach ($balances as $b): ?><tr><td><?= esc($b['code']) ?> <span class="text-muted"><?= esc($b['name']) ?></span></td><td class="num"><?= $b['entitled'] ?></td><td class="num"><?= $b['carried'] ?></td><td class="num"><?= $b['adjusted'] ?></td><td class="num"><?= $b['used'] ?></td><td class="num"><?= $b['pending'] ?></td><td class="num fw-semibold"><?= $b['limited'] ? $b['available'] : '—' ?></td></tr><?php endforeach ?>
        </tbody></table></div>
        <?php if ($leaves): ?><div class="card-body small border-top"><?php foreach ($leaves as $l): ?><div><?= dmy($l['from_date']) ?><?= $l['to_date'] !== $l['from_date'] ? '–' . dmy($l['to_date']) : '' ?> · <?= esc($l['code']) ?> <?= $l['days'] ?>d <?= status_badge($l['status']) ?></div><?php endforeach ?></div><?php endif ?>
    </div>
    <?php endif ?>

    <?php if (can('hr.salary.view')): ?>
    <div class="table-card mb-3" id="salary"><div class="card-header"><strong>Salary structure</strong> <i class="bi bi-lock-fill text-muted" title="Restricted to salary permissions"></i></div>
        <div class="card-body small">
        <?php foreach ($structures as $i => $s): ?>
        <div class="border rounded p-2 mb-2 <?= $i === 0 ? 'border-success' : 'bg-body-tertiary' ?>">
            <div class="d-flex justify-content-between"><strong>From <?= dmy($s['effective_from']) ?><?= $i === 0 ? ' (latest)' : '' ?></strong><span><?= $s['salary_type'] === 'daily' ? inr($s['monthly_gross']) . ' / day' : inr($s['monthly_gross']) . ' / month' ?><?= $s['ctc_annual'] ? ' · CTC ' . inr($s['ctc_annual']) : '' ?></span></div>
            <div class="text-muted">Proration: <?= esc(str_replace('_', ' ', $s['proration_basis'])) ?><?= $s['overtime_rate_per_hour'] ? ' · OT ' . inr($s['overtime_rate_per_hour']) . '/h' : '' ?> · PF <?= $s['pf_applicable'] ? 'yes' : 'no' ?> · ESI <?= $s['esi_applicable'] ? 'yes' : 'no' ?> · PT <?= $s['pt_applicable'] ? 'yes' : 'no' ?><?= (float) $s['tds_monthly'] ? ' · TDS ' . inr($s['tds_monthly']) . '/m' : '' ?></div>
            <?php if ($s['components']): ?><div><?= implode(' · ', array_map(static fn ($c) => esc($c['label']) . ' ' . ($c['component_type'] === 'deduction' ? '−' : '') . inr($c['amount']) . ($c['is_prorated'] ? '' : ' (fixed)'), $s['components'])) ?></div><?php endif ?>
        </div>
        <?php endforeach ?>
        <?php if ($structures === []): ?><p class="text-muted">No salary structure yet — this employee is skipped by payroll until one is added.</p><?php endif ?>
        <?php if (can('hr.salary.manage')): ?>
        <details <?= $structures === [] ? 'open' : '' ?>><summary>Add / revise salary (new effective date)</summary>
        <form method="post" action="<?= site_url('hr/employees/' . $id . '/salary') ?>" class="mt-2"><?= csrf_field() ?>
            <div class="row g-2">
                <div class="col-md-3"><label class="form-label required" for="ss_from">Effective from</label><input type="date" class="form-control form-control-sm" id="ss_from" name="effective_from" value="<?= esc(old('effective_from') ?? date('Y-m-01')) ?>" required></div>
                <div class="col-md-3"><label class="form-label" for="ss_type">Type</label><select class="form-select form-select-sm" id="ss_type" name="salary_type"><option value="monthly">Monthly fixed</option><option value="daily">Daily rate</option></select></div>
                <div class="col-md-3"><label class="form-label required" for="ss_gross">Monthly gross / daily rate (₹)</label><input class="form-control form-control-sm<?= is_invalid('monthly_gross') ?>" id="ss_gross" name="monthly_gross" inputmode="decimal" value="<?= esc((string) old('monthly_gross')) ?>" required><?= field_error('monthly_gross') ?></div>
                <div class="col-md-3"><label class="form-label" for="ss_ctc">Annual CTC (₹)</label><input class="form-control form-control-sm" id="ss_ctc" name="ctc_annual" inputmode="decimal" value="<?= esc((string) old('ctc_annual')) ?>"></div>
                <div class="col-md-4"><label class="form-label" for="ss_pro">Proration for absences / partial month</label><select class="form-select form-select-sm" id="ss_pro" name="proration_basis"><option value="calendar_days">Calendar days in month</option><option value="fixed_30">Fixed 30-day month</option><option value="working_days">Working days only</option></select></div>
                <div class="col-md-2"><label class="form-label" for="ss_ot">Overtime ₹/hour</label><input class="form-control form-control-sm" id="ss_ot" name="overtime_rate_per_hour" inputmode="decimal"></div>
                <div class="col-md-2"><label class="form-label" for="ss_tds">TDS ₹/month</label><input class="form-control form-control-sm" id="ss_tds" name="tds_monthly" inputmode="decimal" value="0"></div>
                <div class="col-md-4 d-flex align-items-end gap-3"><?php foreach (['pf_applicable' => 'PF', 'esi_applicable' => 'ESI', 'pt_applicable' => 'PT'] as $k => $l): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="<?= $k ?>" value="1" id="ss_<?= $k ?>"><label class="form-check-label" for="ss_<?= $k ?>"><?= $l ?></label></div><?php endforeach ?></div>
            </div>
            <div class="form-text">PF/ESI/PT apply only if also enabled and configured in HR → Statutory settings (all off by default).</div>
            <div class="mt-2"><strong>Breakdown (optional)</strong> — earnings must add up to the monthly gross; untick “prorate” for amounts paid in full regardless of attendance.</div>
            <table class="table table-sm mb-2"><thead><tr><th>Code</th><th>Label</th><th>Type</th><th class="num">Amount</th><th>Prorate</th></tr></thead><tbody>
            <?php foreach ([['BASIC', 'Basic'], ['HRA', 'House rent allowance'], ['SPECIAL', 'Special allowance'], ['', '']] as $n => [$c, $lbl]): ?>
            <tr><td><input class="form-control form-control-sm" name="components[<?= $n ?>][code]" value="<?= $c ?>" maxlength="20" aria-label="Code"></td><td><input class="form-control form-control-sm" name="components[<?= $n ?>][label]" value="<?= $lbl ?>" aria-label="Label"></td>
                <td><select class="form-select form-select-sm" name="components[<?= $n ?>][component_type]" aria-label="Type"><option value="earning">Earning</option><option value="deduction">Deduction</option></select></td>
                <td><input class="form-control form-control-sm text-end" name="components[<?= $n ?>][amount]" inputmode="decimal" aria-label="Amount"></td><td><input class="form-check-input" type="checkbox" name="components[<?= $n ?>][is_prorated]" value="1" checked aria-label="Prorate"></td></tr>
            <?php endforeach ?></tbody></table>
            <input class="form-control form-control-sm mb-2" name="notes" placeholder="Note (e.g. annual increment)" aria-label="Note">
            <button class="btn btn-sm btn-primary" type="submit">Save salary structure</button>
        </form></details>
        <?php endif ?>
        </div></div>
    <?php endif ?>

    <?php if (can('hr.payroll.view')): ?>
    <div class="table-card mb-3"><div class="card-header"><strong>Payroll history</strong></div><div class="table-responsive"><table class="table small mb-0">
        <thead><tr><th>Period</th><th class="num">Payable days</th><th class="num">Gross</th><th class="num">Deductions</th><th class="num">Net</th><th class="num">Paid</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($payroll as $p): ?><tr><td><a href="<?= site_url('hr/payroll/' . $p['run_id']) ?>"><?= esc($p['period']) ?></a></td><td class="num"><?= $p['payable_days'] ?></td><td class="num"><?= inr($p['gross_pay']) ?></td><td class="num"><?= inr($p['total_deductions']) ?></td><td class="num fw-semibold"><?= inr($p['net_pay']) ?></td><td class="num"><?= inr($p['amount_paid']) ?></td><td><?= status_badge($p['run_status']) ?> <?= status_badge($p['payment_status']) ?></td></tr><?php endforeach ?>
        <?php if ($payroll === []): ?><tr><td colspan="7" class="text-muted">No payroll yet.</td></tr><?php endif ?>
        </tbody></table></div></div>
    <?php endif ?>

    <?php if (can('hr.incentive.view_all')): ?>
    <div class="table-card mb-3"><div class="card-header d-flex justify-content-between"><strong>Incentives</strong><a class="small" href="<?= site_url('hr/incentives?employee=' . $id) ?>">Ledger</a></div><div class="table-responsive"><table class="table small mb-0"><tbody>
        <?php foreach ($incentives as $e): ?><tr><td><?= esc($e['period']) ?></td><td><?= esc(str_replace('_', ' ', $e['source_type'])) ?></td><td class="num"><?= inr($e['amount']) ?></td><td><?= status_badge($e['status']) ?></td></tr><?php endforeach ?>
        <?php if ($incentives === []): ?><tr><td class="text-muted">No incentive entries.</td></tr><?php endif ?>
        </tbody></table></div></div>
    <?php endif ?>

    <?php if ($timeline !== null): ?>
    <div class="card mb-3"><div class="card-body small">
        <div class="card-section-title">Recruitment history — <a href="<?= site_url('hr/candidates/' . $candidate['id']) ?>"><?= esc($candidate['candidate_code']) ?></a> <span class="text-muted fw-normal">(applied <?= dmy($candidate['application_date']) ?>)</span></div>
        <ul class="timeline list-unstyled mb-0"><?php foreach ($timeline as $t): ?><li class="mb-1"><span class="text-muted"><?= dmy($t['occurred_at'], true) ?></span> — <?= esc($t['title']) ?><?= $t['actor'] ? ' <span class="text-muted">by ' . esc($t['actor']) . '</span>' : '' ?></li><?php endforeach ?></ul>
    </div></div>
    <?php endif ?>
</div>
</div>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="<?= asset('js/banks.js') ?>"></script><?= $this->endSection() ?>
