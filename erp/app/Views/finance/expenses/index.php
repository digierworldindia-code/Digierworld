<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if (can('finance.expense.export')): ?><a class="btn btn-outline-dark btn-sm" href="<?= site_url('finance/expenses/export?' . http_build_query(array_filter($f) + ['format' => 'xlsx'])) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a><?php endif ?>
<a class="btn btn-outline-dark btn-sm" href="<?= site_url('finance/expenses/recurring') ?>"><i class="bi bi-arrow-repeat"></i> Recurring<?= $dueRecurring ? ' <span class="badge text-bg-warning">' . (int) $dueRecurring . ' due</span>' : '' ?></a>
<?php if (can('finance.expense.create')): ?><a href="<?= site_url('finance/expenses/new') ?>" class="btn btn-gold btn-sm"><i class="bi bi-plus-lg"></i> Record expense</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-label">Approved (filtered)</div><div class="kpi-value"><?= inr($sums['approved']) ?></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-label">Eligible ITC</div><div class="kpi-value"><?= inr($sums['itc']) ?></div><div class="kpi-sub">As per accountant's ITC rules</div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-label">Approved, not paid</div><div class="kpi-value"><?= inr($sums['unpaid']) ?></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-label">Awaiting approval</div><div class="kpi-value"><?= (int) $sums['pending'] ?></div><a class="small" href="<?= site_url('finance/expenses?approval=pending') ?>">Show</a></div></div>
</div>
<form class="filter-bar row g-2" method="get">
    <div class="col-md-3"><input type="search" name="q" value="<?= esc($f['q']) ?>" class="form-control form-control-sm" placeholder="Number, vendor, invoice, description" aria-label="Search"></div>
    <div class="col-6 col-md-2"><select name="category" class="form-select form-select-sm" data-autosubmit aria-label="Category"><option value="">Any category</option><?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>" <?= $f['category'] === (int) $c['id'] ? 'selected' : '' ?>><?= esc($c['name']) ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-2"><select name="approval" class="form-select form-select-sm" data-autosubmit aria-label="Approval"><option value="">Any approval</option><?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['approval'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-2"><select name="payment" class="form-select form-select-sm" data-autosubmit aria-label="Payment"><option value="">Any payment</option><?php foreach (['unpaid' => 'Unpaid', 'partial' => 'Part paid', 'paid' => 'Paid'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['payment'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-3"><select name="through" class="form-select form-select-sm" data-autosubmit aria-label="Paid through"><option value="">Any payer</option><option value="company" <?= $f['through'] === 'company' ? 'selected' : '' ?>>Company</option><option value="employee_reimbursable" <?= $f['through'] === 'employee_reimbursable' ? 'selected' : '' ?>>Employee (reimburse)</option></select></div>
    <div class="col-6 col-md-2"><input type="date" name="from" value="<?= esc($f['from'] ?? '') ?>" class="form-control form-control-sm" aria-label="From"></div>
    <div class="col-6 col-md-2"><input type="date" name="to" value="<?= esc($f['to'] ?? '') ?>" class="form-control form-control-sm" aria-label="To"></div>
    <div class="col-md-2"><button class="btn btn-dark btn-sm w-100">Filter</button></div>
</form>
<div class="table-card"><div class="table-responsive">
<table class="table table-hover align-middle small">
    <thead><tr><th>Number</th><th>Date</th><th>Category</th><th>Vendor / description</th><th class="num">Taxable</th><th class="num">GST</th><th class="num">Total</th><th class="num">ITC</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $gst = \App\Libraries\Dec::add($r['cgst_amount'], $r['sgst_amount'], $r['utgst_amount'], $r['igst_amount']); ?>
    <tr>
        <td><a class="mono fw-semibold" href="<?= site_url('finance/expenses/' . $r['id']) ?>"><?= esc($r['expense_number']) ?></a></td>
        <td><?= dmy($r['expense_date']) ?></td><td><?= esc($r['category_name']) ?></td>
        <td><?= esc((string) $r['vendor_name']) ?><div class="text-muted"><?= esc(mb_strimwidth($r['description'], 0, 60, '…')) ?></div></td>
        <td class="num"><?= inr($r['taxable_amount']) ?></td><td class="num"><?= (float) $gst ? inr($gst) : '—' ?></td><td class="num fw-semibold"><?= inr($r['total_amount']) ?></td>
        <td class="num"><?= (float) $r['itc_eligible_amount'] ? inr($r['itc_eligible_amount']) : '—' ?></td>
        <td><?= status_badge($r['approval_status']) ?> <?= $r['approval_status'] === 'approved' ? status_badge($r['payment_status']) : '' ?><?= $r['paid_through'] === 'employee_reimbursable' ? ' <span class="badge text-bg-light border">reimburse</span>' : '' ?></td>
    </tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="9"><div class="empty-state"><i class="bi bi-bag"></i>No expenses match these filters.</div></td></tr><?php endif ?>
    </tbody>
</table>
</div></div>
<?= view('partials/pager', ['total' => $total, 'page' => $page, 'perPage' => $perPage]) ?>
<?= $this->endSection() ?>
