<?= $this->extend('layouts/app') ?>
<?php $locked = (int) $run['is_locked'] === 1; $hasBlock = in_array('block', array_column($warnings, 'level'), true); ?>
<?= $this->section('pageActions') ?>
<?php if (can('hr.salary.export')): ?><a class="btn btn-outline-secondary btn-sm" href="<?= site_url('hr/payroll/' . $run['id'] . '/register') ?>"><i class="bi bi-file-earmark-excel"></i> Salary register</a><?php endif ?>
<?php if (! $locked && can('hr.payroll.calculate')): ?>
<form method="post" action="<?= site_url('hr/payroll/' . $run['id'] . '/calculate') ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-clockwise"></i> Recalculate</button></form>
<form method="post" action="<?= site_url('hr/payroll/' . $run['id'] . '/delete') ?>" class="d-inline" data-confirm="Delete this draft payroll? Included incentives are released." data-confirm-danger><?= csrf_field() ?><button class="btn btn-outline-danger btn-sm">Delete draft</button></form>
<?php endif ?>
<?php if (! $locked && can('hr.payroll.finalize') && $run['status'] === 'calculated'): ?>
<form method="post" action="<?= site_url('hr/payroll/' . $run['id'] . '/finalize') ?>" class="d-inline" data-confirm="Finalize and lock <?= esc($run['period']) ?>? The accrual journal is posted and payslips are issued. Later changes need audited corrections."><?= csrf_field() ?><button class="btn btn-primary btn-sm" <?= $hasBlock ? 'disabled' : '' ?>><i class="bi bi-lock"></i> Finalize &amp; lock</button></form>
<?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="row g-3 mb-3">
    <?php foreach ([['Gross', $run['total_gross']], ['Deductions', $run['total_deductions']], ['Net payable', $run['total_net']], ['Paid', $run['total_paid']]] as [$l, $v]): ?>
    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label"><?= $l ?></div><div class="kpi-value"><?= inr($v) ?></div></div></div>
    <?php endforeach ?>
</div>
<div class="mb-2"><?= status_badge($run['status']) ?> <?= $locked ? '<span class="badge badge-neutral"><i class="bi bi-lock-fill"></i> Locked ' . dmy($run['finalized_at'], true) . '</span>' : '' ?>
    <?= $run['journal_entry_id'] && can('finance.ledger.view') ? ' · <a href="' . site_url('finance/ledger/entries/' . $run['journal_entry_id']) . '">Accrual journal</a>' : '' ?>
    <?= $run['calculated_at'] ? ' <span class="text-muted small">· calculated ' . dmy($run['calculated_at'], true) . '</span>' : '' ?></div>
<?php foreach ($warnings as $w): ?><div class="alert alert-<?= $w['level'] === 'block' ? 'danger' : ($w['level'] === 'warn' ? 'warning' : 'light border') ?> py-1 px-2 small mb-1"><?= esc($w['text']) ?></div><?php endforeach ?>
<form method="post" action="<?= site_url('hr/payroll/' . $run['id'] . '/pay') ?>">
<?= csrf_field() ?>
<div class="table-card mt-2"><div class="table-responsive"><table class="table table-hover align-middle small mb-0">
    <thead><tr><?= $locked && can('hr.payroll.pay') ? '<th></th>' : '' ?><th>Employee</th><th class="num">Payable days</th><th class="num">Fixed</th><th class="num">Incentive</th><th class="num">Other earn.</th><th class="num">Gross</th><th class="num">Deductions</th><th class="num">Net</th><th class="num">Paid</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($items as $i): ?>
    <tr><?= $locked && can('hr.payroll.pay') ? '<td>' . ((float) $i['balance'] > 0 ? '<input class="form-check-input" type="checkbox" name="items[]" value="' . $i['id'] . '" aria-label="Select">' : '') . '</td>' : '' ?>
        <td><a href="<?= site_url('hr/payroll/items/' . $i['id']) ?>"><?= esc($i['full_name']) ?></a><div class="text-muted"><?= esc($i['employee_code']) ?></div></td>
        <td class="num"><?= (float) $i['payable_days'] ?> / <?= (float) $i['proration_days'] ?><?= (float) $i['unmarked_days'] ? '<div class="text-warning">' . (float) $i['unmarked_days'] . ' unmarked</div>' : '' ?></td>
        <td class="num"><?= inr($i['fixed_earned'], false) ?></td><td class="num"><?= (float) $i['incentive_amount'] ? inr($i['incentive_amount'], false) : '—' ?></td>
        <td class="num"><?= inr(\App\Libraries\Dec::add($i['allowances'], $i['overtime_amount'], $i['reimbursement_amount']), false) ?></td>
        <td class="num"><?= inr($i['gross_pay'], false) ?></td><td class="num"><?= inr($i['total_deductions'], false) ?></td><td class="num fw-semibold"><?= inr($i['net_pay'], false) ?></td>
        <td class="num"><?= inr($i['amount_paid'], false) ?></td><td><?= $locked ? status_badge($i['payment_status']) : '' ?></td>
        <td><?= $i['payslip_file'] ? '<a href="' . site_url('files/' . $i['payslip_file']) . '" title="Payslip"><i class="bi bi-file-earmark-pdf"></i></a>' : (can('hr.payslip.view_all') ? '<a href="' . site_url('hr/payroll/items/' . $i['id'] . '/preview') . '" title="Preview payslip (draft)" target="_blank"><i class="bi bi-eye"></i></a>' : '') ?></td></tr>
    <?php endforeach ?>
    <?php if ($items === []): ?><tr><td colspan="12"><div class="empty-state"><i class="bi bi-people"></i>No employees calculated. Check salary structures.</div></td></tr><?php endif ?>
</tbody></table></div></div>
<?php if ($locked && can('hr.payroll.pay') && (float) $run['total_net'] > (float) $run['total_paid']): ?>
<div class="card mt-3"><div class="card-body small row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label" for="pay_date">Payment date</label><input type="date" class="form-control form-control-sm" id="pay_date" name="payment_date" value="<?= date('Y-m-d') ?>"></div>
    <div class="col-md-3"><label class="form-label" for="pay_acc">Paid from</label><select class="form-select form-select-sm" id="pay_acc" name="bank_cash_account_id" required><?php foreach ($accounts as $a): ?><option value="<?= $a['id'] ?>"><?= esc($a['display_name']) ?></option><?php endforeach ?></select></div>
    <div class="col-md-2"><label class="form-label" for="pay_mode">Mode</label><select class="form-select form-select-sm" id="pay_mode" name="mode"><?php foreach (['neft' => 'NEFT', 'imps' => 'IMPS', 'upi' => 'UPI', 'bank_transfer' => 'Bank transfer', 'cash' => 'Cash', 'cheque' => 'Cheque'] as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach ?></select></div>
    <div class="col-md-2"><label class="form-label" for="pay_ref">Reference</label><input class="form-control form-control-sm" id="pay_ref" name="reference_no"></div>
    <div class="col-md-2"><button class="btn btn-primary btn-sm w-100" type="submit">Pay selected (or all unpaid)</button></div>
    <div class="col-12 form-text">Each payment posts Dr Salary Payable / Cr the bank or cash account. The salary expense was already recorded when the month was finalized, so nothing is counted twice.</div>
</div></div>
<?php endif ?>
</form>
<?php if ($adjustments): ?>
<div class="table-card mt-3"><div class="card-header"><strong>Post-lock corrections</strong></div><table class="table small mb-0"><tbody>
    <?php foreach ($adjustments as $a): ?><tr><td><?= dmy($a['created_at'], true) ?></td><td><?= esc($a['full_name']) ?></td><td><?= esc($a['adjustment_type']) ?>: <?= esc($a['label']) ?></td><td class="num"><?= inr($a['amount']) ?></td><td><?= esc($a['reason']) ?></td><td><?= esc((string) $a['by_name']) ?></td></tr><?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<?= $this->endSection() ?>
