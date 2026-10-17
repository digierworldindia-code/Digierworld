<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if (can('hr.incentive.policy_manage')): ?><a class="btn btn-outline-dark btn-sm" href="<?= site_url('hr/incentives/policies') ?>"><i class="bi bi-sliders"></i> Policies</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="row g-3 mb-3">
    <?php foreach (['proposed' => 'Awaiting approval', 'approved' => 'Approved, not yet in payroll', 'included' => 'In payroll (unpaid)', 'paid' => 'Paid'] as $k => $l): ?>
    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label"><?= $l ?></div><div class="kpi-value"><?= inr($sum[$k] ?? 0) ?></div><a class="small" href="<?= site_url('hr/incentives?' . http_build_query(array_filter(['status' => $k, 'period' => $f['period'], 'employee' => $f['employee'] ?: null]))) ?>">Show</a></div></div>
    <?php endforeach ?>
</div>
<div class="row g-3">
<div class="col-xl-9">
<form class="filter-bar row g-2" method="get">
    <div class="col-md-3"><input type="month" name="period" value="<?= esc($f['period']) ?>" class="form-control form-control-sm" aria-label="Month"></div>
    <div class="col-md-4"><select name="employee" class="form-select form-select-sm" data-autosubmit aria-label="Employee"><option value="">All employees</option><?php foreach ($employees as $e): ?><option value="<?= $e['id'] ?>" <?= $f['employee'] === (int) $e['id'] ? 'selected' : '' ?>><?= esc($e['full_name']) ?></option><?php endforeach ?></select></div>
    <div class="col-md-3"><select name="status" class="form-select form-select-sm" data-autosubmit aria-label="Status"><option value="">Any status</option><?php foreach (['proposed', 'approved', 'included', 'paid', 'rejected'] as $s): ?><option <?= $f['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach ?></select></div>
    <div class="col-md-2"><button class="btn btn-dark btn-sm w-100">Filter</button></div>
</form>
<form method="post" action="<?= site_url('hr/incentives/approve') ?>" id="bulk"><?= csrf_field() ?></form>
<div class="table-card"><div class="table-responsive"><table class="table align-middle small mb-0">
    <thead><tr><th></th><th>Employee</th><th>Month</th><th>Source</th><th class="num">Basis</th><th class="num">Share</th><th class="num">Incentive</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
    <tr class="<?= (float) $r['amount'] < 0 ? 'table-danger-subtle' : '' ?>">
        <td><?= $r['status'] === 'proposed' && can('hr.incentive.approve') ? '<input class="form-check-input" type="checkbox" name="ids[]" value="' . $r['id'] . '" form="bulk" aria-label="Select">' : '' ?></td>
        <td><a href="<?= site_url('hr/employees/' . $r['employee_id']) ?>"><?= esc($r['full_name']) ?></a><div class="text-muted"><?= esc($r['policy']) ?></div></td>
        <td><?= esc($r['period']) ?><?= $r['paid_period'] ? '<div class="text-muted">payroll ' . esc($r['paid_period']) . '</div>' : '' ?></td>
        <td><?= esc(str_replace('_', ' ', $r['source_type'])) ?><div class="text-muted"><?= esc((string) ($r['notes'] ?: ($r['document_number'] ?? $r['company_name'] ?? ''))) ?></div></td>
        <td class="num"><?= inr($r['basis_amount']) ?><div class="text-muted"><?= esc($bases[$r['basis']] ?? $r['basis']) ?></div></td><td class="num"><?= (float) $r['share_percent'] ?>%</td>
        <td class="num fw-semibold"><?= inr($r['amount']) ?></td><td><?= status_badge($r['status']) ?><?= $r['rejection_reason'] ? '<div class="text-muted">' . esc($r['rejection_reason']) . '</div>' : '' ?></td>
        <td class="text-end"><?php if (in_array($r['status'], ['proposed', 'approved'], true) && can('hr.incentive.approve')): ?>
            <details><summary class="small">Act</summary>
            <form method="post" action="<?= site_url('hr/incentives/' . $r['id'] . '/decide') ?>" class="d-flex gap-1 mt-1"><?= csrf_field() ?><input class="form-control form-control-sm" name="reason" placeholder="Reason (to reject)" aria-label="Reason">
                <?php if ($r['status'] === 'proposed'): ?><button class="btn btn-sm btn-outline-success" name="decision" value="approved">Approve</button><?php endif ?><button class="btn btn-sm btn-outline-danger" name="decision" value="rejected">Reject</button></form>
            <?php if ($r['source_type'] !== 'clawback'): ?>
            <form method="post" action="<?= site_url('hr/incentives/' . $r['id'] . '/split') ?>" class="d-flex gap-1 mt-1"><?= csrf_field() ?>
                <select class="form-select form-select-sm" name="employee_id" aria-label="Split with"><?php foreach ($employees as $e): if ((int) $e['id'] === (int) $r['employee_id']) { continue; } ?><option value="<?= $e['id'] ?>"><?= esc($e['full_name']) ?></option><?php endforeach ?></select>
                <input class="form-control form-control-sm" name="percent" placeholder="%" style="max-width:60px" aria-label="Percent"><input class="form-control form-control-sm" name="reason" placeholder="Why" aria-label="Reason"><button class="btn btn-sm btn-outline-dark">Split</button></form>
            <?php endif ?></details>
        <?php endif ?></td></tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="9"><div class="empty-state"><i class="bi bi-trophy"></i>No incentive entries. Set up a policy, then generate proposals for a month.</div></td></tr><?php endif ?>
</tbody></table></div></div>
<?php if (can('hr.incentive.approve')): ?><button class="btn btn-sm btn-outline-success mt-2" form="bulk">Approve selected</button><?php endif ?>
</div>
<div class="col-xl-3">
    <?php if (can(['hr.incentive.approve', 'hr.incentive.policy_manage'])): ?>
    <form method="post" action="<?= site_url('hr/incentives/generate') ?>" class="card mb-3"><div class="card-body small"><?= csrf_field() ?>
        <div class="card-section-title">Generate proposals</div>
        <input type="month" class="form-control form-control-sm mb-2" name="period" value="<?= esc($f['period'] ?: date('Y-m')) ?>" required aria-label="Month">
        <button class="btn btn-dark btn-sm w-100">Generate for month</button>
        <div class="form-text">Reads CRM conversions, invoices and collections per active policy. Safe to repeat: each source creates an entry only once. Cancelled invoices, credit notes and voided receipts reverse or claw back.</div>
    </div></form>
    <?php endif ?>
    <?php if (can('hr.incentive.approve')): ?>
    <form method="post" action="<?= site_url('hr/incentives/manual') ?>" class="card"><div class="card-body small"><?= csrf_field() ?>
        <div class="card-section-title">Manual entry</div>
        <select class="form-select form-select-sm mb-2" name="employee_id" required aria-label="Employee"><option value="">Employee…</option><?php foreach ($employees as $e): ?><option value="<?= $e['id'] ?>"><?= esc($e['full_name']) ?></option><?php endforeach ?></select>
        <select class="form-select form-select-sm mb-2" name="policy_id" required aria-label="Policy"><?php foreach ($policies as $p): ?><option value="<?= $p['id'] ?>"><?= esc($p['name']) ?></option><?php endforeach ?></select>
        <div class="row g-2 mb-2"><div class="col-6"><input type="month" class="form-control form-control-sm" name="period" value="<?= date('Y-m') ?>" aria-label="Month"></div><div class="col-6"><input class="form-control form-control-sm" name="amount" placeholder="₹ (− for recovery)" inputmode="decimal" aria-label="Amount"></div></div>
        <input class="form-control form-control-sm mb-2" name="notes" placeholder="Reason" required aria-label="Reason">
        <button class="btn btn-outline-dark btn-sm w-100" <?= $policies ? '' : 'disabled' ?>>Add for approval</button>
    </div></form>
    <?php endif ?>
</div>
</div>
<?= $this->endSection() ?>
