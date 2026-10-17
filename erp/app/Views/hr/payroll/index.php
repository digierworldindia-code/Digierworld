<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if (can('hr.payroll.statutory')): ?><a class="btn btn-outline-dark btn-sm" href="<?= site_url('hr/payroll/statutory') ?>"><i class="bi bi-sliders"></i> Statutory settings</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="row g-3">
<div class="col-xl-8">
    <div class="table-card"><div class="table-responsive"><table class="table table-hover align-middle small mb-0">
        <thead><tr><th>Month</th><th class="num">Employees</th><th class="num">Gross</th><th class="num">Deductions</th><th class="num">Net</th><th class="num">Paid</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($runs as $r): ?>
        <tr><td><a class="fw-semibold" href="<?= site_url('hr/payroll/' . $r['id']) ?>"><?= date('F Y', strtotime($r['period'] . '-01')) ?></a></td><td class="num"><?= (int) $r['headcount'] ?></td>
            <td class="num"><?= inr($r['total_gross']) ?></td><td class="num"><?= inr($r['total_deductions']) ?></td><td class="num fw-semibold"><?= inr($r['total_net']) ?></td><td class="num"><?= inr($r['total_paid']) ?></td>
            <td><?= status_badge($r['status']) ?><?= $r['is_locked'] ? ' <i class="bi bi-lock-fill" title="Locked"></i>' : '' ?></td></tr>
        <?php endforeach ?>
        <?php if ($runs === []): ?><tr><td colspan="7"><div class="empty-state"><i class="bi bi-cash-stack"></i>No payroll yet. Add salary structures on employee profiles, mark attendance, then create the month's payroll.</div></td></tr><?php endif ?>
    </tbody></table></div></div>
    <?php if ($advances): ?>
    <div class="table-card mt-3"><div class="card-header bg-white"><strong>Open salary advances</strong></div><table class="table small mb-0"><tbody>
        <?php foreach ($advances as $a): ?><tr><td><?= esc($a['full_name']) ?></td><td><?= dmy($a['advance_date']) ?></td><td class="num"><?= inr($a['amount']) ?></td><td class="num">recovered <?= inr($a['recovered_amount']) ?></td><td class="num"><?= inr($a['monthly_recovery']) ?>/month</td></tr><?php endforeach ?>
    </tbody></table></div>
    <?php endif ?>
</div>
<div class="col-xl-4">
    <?php if (can('hr.payroll.calculate')): ?>
    <form method="post" action="<?= site_url('hr/payroll') ?>" class="card mb-3"><div class="card-body small"><?= csrf_field() ?>
        <div class="card-section-title">Run payroll</div>
        <div class="input-group"><input type="month" class="form-control" name="period" value="<?= date('Y-m', strtotime('first day of last month')) ?>" max="<?= date('Y-m') ?>" required aria-label="Month"><button class="btn btn-dark">Create &amp; calculate</button></div>
        <div class="form-text">Uses attendance, approved leave, salary structures, approved incentives (each only once), reimbursements, advances and enabled statutory items. Nothing is posted to accounts until you finalize.</div>
    </div></form>
    <?php endif ?>
    <div class="card mb-3"><div class="card-body small"><div class="card-section-title">Statutory deductions</div>
        <?php foreach ($statutory as $s): ?><div class="d-flex justify-content-between"><span><?= esc($s['label']) ?></span><?= $s['enabled'] ? status_badge('active') : '<span class="badge text-bg-secondary">off</span>' ?></div><?php endforeach ?>
        <div class="form-text">All off by default. Enable only after your accountant confirms applicability and rates.</div></div></div>
    <?php if (can('hr.payroll.pay')): ?>
    <form method="post" action="<?= site_url('hr/payroll/advances') ?>" class="card"><div class="card-body small"><?= csrf_field() ?>
        <div class="card-section-title">Give a salary advance</div>
        <select class="form-select form-select-sm mb-2" name="employee_id" required aria-label="Employee"><option value="">Employee…</option><?php foreach ($employees as $e): ?><option value="<?= $e['id'] ?>"><?= esc($e['full_name']) ?></option><?php endforeach ?></select>
        <div class="row g-2 mb-2"><div class="col-6"><input class="form-control form-control-sm" name="amount" placeholder="Amount ₹" inputmode="decimal" required aria-label="Amount"></div><div class="col-6"><input class="form-control form-control-sm" name="monthly_recovery" placeholder="Recover ₹/month" inputmode="decimal" required aria-label="Monthly recovery"></div></div>
        <div class="row g-2 mb-2"><div class="col-6"><input type="date" class="form-control form-control-sm" name="advance_date" value="<?= date('Y-m-d') ?>" aria-label="Date"></div><div class="col-6"><select class="form-select form-select-sm" name="bank_cash_account_id" required aria-label="Paid from"><?php foreach ($accounts as $a): ?><option value="<?= $a['id'] ?>"><?= esc($a['display_name']) ?></option><?php endforeach ?></select></div></div>
        <input class="form-control form-control-sm mb-2" name="notes" placeholder="Note" aria-label="Note">
        <button class="btn btn-outline-dark btn-sm w-100">Record advance</button>
    </div></form>
    <?php endif ?>
</div>
</div>
<?= $this->endSection() ?>
