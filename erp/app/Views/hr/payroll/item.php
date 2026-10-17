<?= $this->extend('layouts/app') ?>
<?php $locked = (int) $run['is_locked'] === 1; $types = ['earning' => 'Earnings', 'deduction' => 'Deductions', 'employer' => 'Employer contributions (not deducted)']; ?>
<?= $this->section('content') ?>
<div class="row g-3">
<div class="col-xl-7">
    <div class="card mb-3"><div class="card-body small">
        <div class="d-flex justify-content-between"><div><strong><?= esc($item['full_name']) ?></strong> · <?= esc($item['employee_code']) ?> · <?= date('F Y', strtotime($run['period'] . '-01')) ?></div><div><?= status_badge($run['status']) ?> <?= $locked ? status_badge($item['payment_status']) : '' ?></div></div>
        <div class="row mt-2 text-muted"><div class="col">Payable <?= (float) $item['payable_days'] ?> / <?= (float) $item['proration_days'] ?> days</div><div class="col">Present <?= (float) $item['present_days'] ?></div><div class="col">Paid leave <?= (float) $item['paid_leave_days'] ?></div><div class="col">Unpaid <?= (float) $item['unpaid_leave_days'] ?> · Absent <?= (float) $item['absent_days'] ?></div><div class="col">Unmarked <?= (float) $item['unmarked_days'] ?></div></div>
    </div></div>
    <?php foreach ($types as $t => $label): $rows = array_filter($components, static fn ($c) => $c['component_type'] === $t); if (! $rows) { continue; } ?>
    <div class="table-card mb-3"><div class="card-header bg-white"><strong><?= $label ?></strong></div><table class="table small mb-0"><tbody>
        <?php foreach ($rows as $c): ?><tr><td><?= esc($c['label']) ?><?= $c['ref_type'] === 'manual' ? ' <span class="badge text-bg-light border">manual</span>' : '' ?></td><td class="num"><?= inr($c['amount']) ?></td>
            <td class="text-end"><?php if ($c['ref_type'] === 'manual' && ! $locked && can('hr.payroll.calculate')): ?><form method="post" action="<?= site_url('hr/payroll/lines/' . $c['id'] . '/delete') ?>"><?= csrf_field() ?><button class="btn btn-link btn-sm text-danger p-0">remove</button></form><?php endif ?></td></tr><?php endforeach ?>
    </tbody></table></div>
    <?php endforeach ?>
    <div class="card"><div class="card-body small"><table class="table table-sm mb-0">
        <tr><td>Gross pay</td><td class="num"><?= inr($item['gross_pay']) ?></td></tr><tr><td>Total deductions</td><td class="num"><?= inr($item['total_deductions']) ?></td></tr>
        <tr class="fw-semibold"><td>Net pay</td><td class="num"><?= inr($item['net_pay']) ?></td></tr><tr><td>Paid</td><td class="num"><?= inr($item['amount_paid']) ?></td></tr><tr><td>Balance</td><td class="num"><?= inr($item['balance']) ?></td></tr>
    </table></div></div>
</div>
<div class="col-xl-5">
    <?php if (! $locked && can('hr.payroll.calculate')): ?>
    <form method="post" action="<?= site_url('hr/payroll/items/' . $item['id'] . '/lines') ?>" class="card mb-3"><div class="card-body small"><?= csrf_field() ?>
        <div class="card-section-title">Add a one-off line (draft)</div>
        <div class="row g-2"><div class="col-4"><select class="form-select form-select-sm" name="component_type" aria-label="Type"><option value="earning">Earning</option><option value="deduction">Deduction</option></select></div>
            <div class="col-5"><input class="form-control form-control-sm" name="label" placeholder="e.g. Festival bonus" required aria-label="Label"></div><div class="col-3"><input class="form-control form-control-sm" name="amount" placeholder="₹" inputmode="decimal" required aria-label="Amount"></div></div>
        <button class="btn btn-dark btn-sm mt-2">Add and recalculate</button>
    </div></form>
    <?php endif ?>
    <?php if ($locked && can('hr.payroll.adjust')): ?>
    <form method="post" action="<?= site_url('hr/payroll/items/' . $item['id'] . '/adjust') ?>" class="card mb-3 border-warning"><div class="card-body small"><?= csrf_field() ?>
        <div class="card-section-title">Correction after lock (audited)</div>
        <div class="row g-2"><div class="col-4"><select class="form-select form-select-sm" name="adjustment_type" aria-label="Type"><option value="earning">Add earning</option><option value="deduction">Add deduction</option></select></div>
            <div class="col-5"><input class="form-control form-control-sm" name="label" placeholder="What is corrected" required aria-label="Label"></div><div class="col-3"><input class="form-control form-control-sm" name="amount" placeholder="₹" inputmode="decimal" required aria-label="Amount"></div></div>
        <textarea class="form-control form-control-sm mt-2" name="reason" rows="2" placeholder="Reason (required, kept in the audit log)" required aria-label="Reason"></textarea>
        <button class="btn btn-warning btn-sm mt-2">Record correction</button>
        <div class="form-text">Posts its own journal and issues a revised payslip version. The original lines are never edited.</div>
    </div></form>
    <?php endif ?>
    <?php if ($locked && can('hr.payroll.pay') && (float) $item['balance'] > 0): ?>
    <form method="post" action="<?= site_url('hr/payroll/items/' . $item['id'] . '/pay') ?>" class="card mb-3"><div class="card-body small"><?= csrf_field() ?>
        <div class="card-section-title">Record payment</div>
        <div class="row g-2"><div class="col-6"><input class="form-control form-control-sm" name="amount" value="<?= esc($item['balance']) ?>" inputmode="decimal" aria-label="Amount"></div><div class="col-6"><input type="date" class="form-control form-control-sm" name="payment_date" value="<?= date('Y-m-d') ?>" aria-label="Date"></div>
            <div class="col-6"><select class="form-select form-select-sm" name="bank_cash_account_id" aria-label="Paid from"><?php foreach ($accounts as $a): ?><option value="<?= $a['id'] ?>"><?= esc($a['display_name']) ?></option><?php endforeach ?></select></div>
            <div class="col-6"><select class="form-select form-select-sm" name="mode" aria-label="Mode"><option value="neft">NEFT</option><option value="imps">IMPS</option><option value="upi">UPI</option><option value="cash">Cash</option><option value="cheque">Cheque</option></select></div>
            <div class="col-12"><input class="form-control form-control-sm" name="reference_no" placeholder="Reference / UTR" aria-label="Reference"></div></div>
        <button class="btn btn-dark btn-sm mt-2">Record payment</button>
    </div></form>
    <?php endif ?>
    <div class="card mb-3"><div class="card-body small"><div class="card-section-title">Payments</div>
        <?php foreach ($payments as $p): ?><div><?= dmy($p['payment_date']) ?> · <?= inr($p['amount']) ?> · <?= esc($p['display_name']) ?> · <?= esc(str_replace('_', ' ', $p['mode'])) ?> <?= esc((string) $p['reference_no']) ?></div><?php endforeach ?>
        <?= $payments ? '' : '<p class="text-muted mb-0">None yet.</p>' ?></div></div>
    <div class="card mb-3"><div class="card-body small"><div class="card-section-title">Payslips</div>
        <?php foreach ($payslips as $p): ?><div><a href="<?= site_url('files/' . $p['stored_file_id']) ?>"><i class="bi bi-file-earmark-pdf"></i> Version <?= (int) $p['version'] ?></a> · <?= dmy($p['generated_at'], true) ?></div><?php endforeach ?>
        <?php if (! $payslips && can('hr.payslip.view_all')): ?><a href="<?= site_url('hr/payroll/items/' . $item['id'] . '/preview') ?>" target="_blank">Preview (draft)</a><?php endif ?></div></div>
    <?php if ($adjustments): ?><div class="card"><div class="card-body small"><div class="card-section-title">Corrections</div><?php foreach ($adjustments as $a): ?><div><?= dmy($a['created_at'], true) ?> · <?= esc($a['adjustment_type']) ?> <?= inr($a['amount']) ?> — <?= esc($a['label']) ?><div class="text-muted"><?= esc($a['reason']) ?></div></div><?php endforeach ?></div></div><?php endif ?>
</div>
</div>
<?= $this->endSection() ?>
