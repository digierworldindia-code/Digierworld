<?= $this->extend('layouts/app') ?>
<?php $D = \App\Libraries\Dec::class; $tax = $D::add($e['cgst_amount'], $e['sgst_amount'], $e['utgst_amount'], $e['igst_amount']); $due = $D::sub($e['total_amount'], $e['paid_amount']); ?>
<?= $this->section('pageActions') ?>
<?php if ($canEdit): ?><a class="btn btn-outline-dark btn-sm" href="<?= site_url('finance/expenses/' . $e['id'] . '/edit') ?>"><i class="bi bi-pencil"></i> Edit</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="d-flex flex-wrap gap-2 mb-3 small">
    <?= status_badge($e['approval_status']) ?> <?= $e['approval_status'] === 'approved' ? status_badge($e['payment_status']) : '' ?>
    <?php if ($e['paid_through'] === 'employee_reimbursable'): ?><span class="badge text-bg-light border">Reimburse <?= esc((string) $e['employee_name']) ?> · <?= esc($e['reimbursement_status']) ?></span><?php endif ?>
</div>
<?php if ($e['approval_status'] === 'rejected'): ?><div class="alert alert-secondary small"><strong>Rejected</strong> by <?= esc((string) $e['approver']) ?>: <?= esc((string) $e['rejection_reason']) ?></div><?php endif ?>
<div class="row g-3">
<div class="col-lg-8">
    <div class="card mb-3"><div class="card-body small">
        <div class="row g-3">
            <div class="col-sm-6"><div class="text-muted">Vendor</div><?= $e['vendor_contact_id'] ? '<a href="' . site_url('contacts/' . $e['vendor_contact_id']) . '">' . esc($e['vendor_name']) . '</a>' : '—' ?><div class="mono text-muted"><?= esc((string) $e['vendor_gstin']) ?></div></div>
            <div class="col-sm-3"><div class="text-muted">Vendor invoice</div><?= esc((string) $e['vendor_invoice_no']) ?: '—' ?><div class="text-muted"><?= dmy($e['vendor_invoice_date']) ?></div></div>
            <div class="col-sm-3"><div class="text-muted">Expense date</div><?= dmy($e['expense_date']) ?></div>
            <div class="col-sm-6"><div class="text-muted">Category</div><?= esc($e['category_name']) ?></div>
            <div class="col-sm-6"><div class="text-muted">SAC / HSN</div><?= esc((string) $e['sac_hsn']) ?: '—' ?></div>
            <div class="col-12"><div class="text-muted">Description</div><?= esc($e['description']) ?></div>
            <?php if ($e['notes']): ?><div class="col-12"><div class="text-muted">Notes</div><?= esc($e['notes']) ?></div><?php endif ?>
            <div class="col-sm-6"><div class="text-muted">Recorded by</div><?= esc((string) $e['creator']) ?> · <?= dmy($e['created_at'], true) ?></div>
            <?php if ($e['approved_at']): ?><div class="col-sm-6"><div class="text-muted"><?= $e['approval_status'] === 'rejected' ? 'Rejected' : 'Approved' ?> by</div><?= esc((string) $e['approver']) ?> · <?= dmy($e['approved_at'], true) ?></div><?php endif ?>
        </div>
    </div></div>

    <?php if ($canApprove): ?>
    <div class="card mb-3 border-warning"><div class="card-body">
        <div class="card-section-title">Approval</div>
        <form method="post" action="<?= site_url('finance/expenses/' . $e['id'] . '/approve') ?>" class="row g-2 align-items-end mb-2">
            <?= csrf_field() ?>
            <?php if (can(['finance.expense.approve', 'finance.expense.manage_categories'])): ?>
            <div class="col-md-8"><label class="form-label small" for="ap_itc">Input tax credit decision</label><select class="form-select form-select-sm" id="ap_itc" name="itc_rule_id">
                <?php foreach ($itcRules as $r): ?><option value="<?= $r['id'] ?>" <?= (int) $r['id'] === (int) $e['itc_rule_id'] ? 'selected' : '' ?>><?= esc($r['name']) ?></option><?php endforeach ?>
            </select></div>
            <?php endif ?>
            <div class="col-md-4"><button class="btn btn-dark btn-sm w-100" type="submit"><i class="bi bi-check2"></i> Approve &amp; post</button></div>
        </form>
        <form method="post" action="<?= site_url('finance/expenses/' . $e['id'] . '/reject') ?>" class="d-flex gap-2">
            <?= csrf_field() ?><input class="form-control form-control-sm" name="reason" placeholder="Reason for rejecting" aria-label="Reason for rejecting" minlength="5" required><button class="btn btn-outline-danger btn-sm" type="submit">Reject</button>
        </form>
    </div></div>
    <?php elseif ($sodBlocked): ?>
    <div class="alert alert-info small"><i class="bi bi-people"></i> Separation of duties: you recorded this expense, so another approver must approve it.</div>
    <?php endif ?>

    <div class="table-card mb-3"><div class="card-header bg-white"><strong>Payments</strong></div><div class="table-responsive"><table class="table small align-middle mb-0">
        <thead><tr><th>Date</th><th>Mode</th><th>Account</th><th>Reference</th><th class="num">Amount</th><th>Bank</th></tr></thead><tbody>
        <?php foreach ($payments as $p): ?><tr><td><?= dmy($p['payment_date']) ?></td><td><?= esc($p['mode']) ?></td><td><?= esc($p['account_name']) ?></td><td class="mono"><?= esc((string) $p['reference_no']) ?></td><td class="num"><?= inr($p['amount']) ?></td><td><?= esc($p['reconciliation_status']) ?></td></tr><?php endforeach ?>
        <?php if ($payments === []): ?><tr><td colspan="6" class="text-muted">No payments yet.</td></tr><?php endif ?>
        </tbody></table></div></div>

    <?php if ($e['approval_status'] === 'approved' && (float) $due > 0 && can('finance.expense.pay')): ?>
    <form method="post" action="<?= site_url('finance/expenses/' . $e['id'] . '/pay') ?>" class="card mb-3"><div class="card-body">
        <?= csrf_field() ?>
        <div class="card-section-title"><?= $e['paid_through'] === 'employee_reimbursable' ? 'Reimburse employee' : 'Record payment' ?> (due <?= inr($due) ?>)</div>
        <div class="row g-2">
            <div class="col-md-3"><input class="form-control form-control-sm" name="amount" inputmode="decimal" value="<?= esc($due) ?>" aria-label="Amount" required></div>
            <div class="col-md-3"><input type="date" class="form-control form-control-sm" name="payment_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" aria-label="Date"></div>
            <div class="col-md-3"><select class="form-select form-select-sm" name="mode" aria-label="Mode"><?php foreach ($modes as $m): ?><option value="<?= $m ?>" <?= $m === 'bank_transfer' ? 'selected' : '' ?>><?= esc(ucwords(str_replace('_', ' ', $m))) ?></option><?php endforeach ?></select></div>
            <div class="col-md-3"><select class="form-select form-select-sm" name="bank_cash_account_id" aria-label="Paid from" required><?php foreach ($accounts as $a): ?><option value="<?= $a['id'] ?>"><?= esc($a['display_name']) ?></option><?php endforeach ?></select></div>
            <div class="col-md-9"><input class="form-control form-control-sm" name="reference_no" placeholder="UTR / cheque no." aria-label="Reference"></div>
            <div class="col-md-3"><button class="btn btn-dark btn-sm w-100" type="submit">Record payment</button></div>
        </div>
    </div></form>
    <?php endif ?>

    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">Bills &amp; receipts</div>
        <?php foreach ($files as $f): ?><div class="small"><i class="bi bi-paperclip"></i> <a href="<?= site_url('files/' . $f['id']) ?>" target="_blank" rel="noopener"><?= esc($f['original_name']) ?></a> <span class="text-muted"><?= number_format($f['size_bytes'] / 1024, 0) ?> KB</span></div><?php endforeach ?>
        <?php if ($files === []): ?><div class="small text-muted mb-2">No bill attached. Attach the vendor's tax invoice to support the ITC claim.</div><?php endif ?>
        <form method="post" action="<?= site_url('finance/expenses/' . $e['id'] . '/files') ?>" enctype="multipart/form-data" class="d-flex gap-2 mt-2">
            <?= csrf_field() ?><input type="file" class="form-control form-control-sm" name="bills[]" accept=".pdf,.png,.jpg,.jpeg" multiple required aria-label="Bill file"><button class="btn btn-outline-dark btn-sm" type="submit">Attach</button>
        </form>
    </div></div>
</div>
<div class="col-lg-4">
    <div class="card totals-box mb-3"><div class="card-body small">
        <div class="d-flex justify-content-between"><span>Amount before GST</span><span><?= inr($e['taxable_amount']) ?></span></div>
        <?php foreach (['cgst' => 'CGST', 'sgst' => 'SGST', 'utgst' => 'UTGST', 'igst' => 'IGST'] as $k => $l): if ((float) $e[$k . '_amount'] > 0): ?>
        <div class="d-flex justify-content-between"><span><?= $l ?> @ <?= esc($D::rateLabel($k === 'igst' ? $e['gst_rate'] : $D::div($e['gst_rate'], '2', 3))) ?>%</span><span><?= inr($e[$k . '_amount']) ?></span></div>
        <?php endif; endforeach ?>
        <div class="d-flex justify-content-between border-top mt-2 pt-2 fs-6 fw-semibold"><span>Total</span><span><?= inr($e['total_amount']) ?></span></div>
        <div class="d-flex justify-content-between"><span>Paid</span><span><?= inr($e['paid_amount']) ?></span></div>
        <hr>
        <div class="d-flex justify-content-between"><span>ITC eligible</span><strong><?= inr($e['itc_eligible_amount']) ?></strong></div>
        <div class="d-flex justify-content-between"><span>ITC not eligible (cost)</span><span><?= inr($e['itc_ineligible_amount']) ?></span></div>
        <div class="text-muted mt-1">Rule: <?= esc((string) $e['itc_rule_name']) ?: 'none' ?><?= $e['itc_review'] === 'suggested' ? ' <span class="badge text-bg-warning">not yet reviewed by accountant</span>' : '' ?></div>
        <?php if ($e['journal_entry_id'] && can('finance.ledger.view')): ?><div class="mt-2"><a href="<?= site_url('finance/ledger/entries/' . $e['journal_entry_id']) ?>">View journal entry</a></div><?php endif ?>
    </div></div>
    <div class="card"><div class="card-body">
        <div class="card-section-title">History</div>
        <ul class="timeline small"><?php foreach ($history as $h): ?><li><div><?= esc($h['summary'] ?: $h['action']) ?></div><div class="text-muted"><?= dmy($h['occurred_at'], true) ?> · <?= esc((string) $h['full_name']) ?></div></li><?php endforeach ?></ul>
    </div></div>
</div>
</div>
<?= $this->endSection() ?>
