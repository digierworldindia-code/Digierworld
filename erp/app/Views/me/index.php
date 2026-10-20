<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="row g-3">
<div class="col-xl-4">
    <div class="card mb-3"><div class="card-body small">
        <div class="h5 mb-0"><?= esc($me['full_name']) ?></div><div class="text-muted mono"><?= esc($me['employee_code']) ?></div>
        <dl class="row mb-0 mt-3">
            <dt class="col-5">Department</dt><dd class="col-7"><?= esc((string) $department) ?: '—' ?></dd>
            <dt class="col-5">Designation</dt><dd class="col-7"><?= esc((string) $designation) ?: '—' ?></dd>
            <dt class="col-5">Reports to</dt><dd class="col-7"><?= esc((string) $manager) ?: '—' ?></dd>
            <dt class="col-5">Joined</dt><dd class="col-7"><?= dmy($me['date_of_joining']) ?></dd>
            <dt class="col-5">Work email</dt><dd class="col-7 text-break"><?= esc((string) $me['work_email']) ?: '—' ?></dd>
        </dl>
        <p class="form-text mb-0 mt-2">Something wrong? Ask HR to update your record.</p>
    </div></div>
    <div class="card mb-3"><div class="card-body small">
        <div class="card-section-title">Today — <?= dmy(date('Y-m-d')) ?></div>
        <?php if ($dayKind): ?><p><span class="badge badge-neutral"><?= $dayKind === 'holiday' ? 'Holiday' : 'Weekly off' ?></span></p><?php endif ?>
        <?php if ($todayMark): ?>
            <p class="mb-2"><?= status_badge($todayMark['status']) ?><?= $todayMark['check_in_at'] ? ' In ' . date('h:i A', strtotime($todayMark['check_in_at'])) : '' ?><?= $todayMark['check_out_at'] ? ' · Out ' . date('h:i A', strtotime($todayMark['check_out_at'])) : '' ?></p>
        <?php else: ?><p class="text-muted mb-2">Not marked yet.</p><?php endif ?>
        <?php if ($canPunch && (! $todayMark || ($todayMark['check_in_at'] && ! $todayMark['check_out_at'] && ! $todayMark['leave_request_id']))): ?>
        <form method="post" action="<?= site_url('me/punch') ?>"><?= csrf_field() ?><button class="btn btn-primary w-100" type="submit"><i class="bi bi-fingerprint"></i> <?= $todayMark ? 'Check out' : 'Check in' ?></button></form>
        <?php elseif (! $canPunch): ?><p class="form-text mb-0">Your attendance is marked by HR.</p><?php endif ?>
    </div></div>
</div>
<div class="col-xl-8">
    <?php if ($month): ?>
    <div class="row g-3 mb-3">
        <?php foreach ([['Present this month', $month['present']], ['Paid leave', $month['paid_leave']], ['Unpaid / absent', $month['unpaid_leave'] + $month['absent']], ['Open tasks', $tasks]] as [$l, $n]): ?>
        <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label"><?= $l ?></div><div class="kpi-value"><?= rtrim(rtrim(number_format((float) $n, 1), '0'), '.') ?></div></div></div>
        <?php endforeach ?>
    </div>
    <?php endif ?>
    <div class="row g-3">
        <?php if ($balances): ?>
        <div class="col-md-6"><div class="table-card"><div class="card-header d-flex justify-content-between"><strong>Leave balance</strong><a class="small" href="<?= site_url('me/leaves') ?>">Request leave</a></div>
            <table class="table small mb-0"><tbody><?php foreach ($balances as $b): ?><tr><td><?= esc($b['name']) ?></td><td class="num"><?= $b['limited'] ? '<strong>' . $b['available'] . '</strong> left' : 'used ' . $b['used'] ?></td></tr><?php endforeach ?></tbody></table></div></div>
        <?php endif ?>
        <?php if (can('self.payslip.view')): ?>
        <div class="col-md-6"><div class="table-card"><div class="card-header d-flex justify-content-between"><strong>Payslips</strong><a class="small" href="<?= site_url('me/payslips') ?>">All</a></div>
            <table class="table small mb-0"><tbody><?php foreach ($payslips as $p): ?><tr><td><?= date('F Y', strtotime($p['period'] . '-01')) ?></td><td class="num"><?= inr($p['net_pay']) ?></td><td><?= $p['stored_file_id'] ? '<a href="' . site_url('files/' . $p['stored_file_id']) . '"><i class="bi bi-file-earmark-pdf"></i> PDF</a>' : '' ?></td></tr><?php endforeach ?>
            <?php if ($payslips === []): ?><tr><td class="text-muted">No finalized payslips yet.</td></tr><?php endif ?></tbody></table></div></div>
        <?php endif ?>
    </div>
    <div class="d-flex flex-wrap gap-2 mt-3">
        <?php if (can('self.attendance.view')): ?><a class="btn btn-outline-secondary btn-sm" href="<?= site_url('me/attendance') ?>"><i class="bi bi-calendar-check"></i> My attendance</a><?php endif ?>
        <?php if (can('self.incentive.view')): ?><a class="btn btn-outline-secondary btn-sm" href="<?= site_url('me/incentives') ?>"><i class="bi bi-trophy"></i> My incentives</a><?php endif ?>
        <?php if (can(['crm.task.view_own', 'crm.task.create'])): ?><a class="btn btn-outline-secondary btn-sm" href="<?= site_url('crm/tasks') ?>"><i class="bi bi-list-check"></i> My tasks (<?= (int) $tasks ?>)</a><?php endif ?>
    </div>
</div>
</div>
<?= $this->endSection() ?>
