<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if (can('reports.export')): ?>
<div class="btn-group btn-group-sm"><a class="btn btn-outline-dark" href="<?= site_url('finance/receipts/export?' . http_build_query(array_filter($f) + ['format' => 'xlsx'])) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a><a class="btn btn-outline-dark" href="<?= site_url('finance/receipts/export?' . http_build_query(array_filter($f) + ['format' => 'csv'])) ?>">CSV</a></div>
<?php endif ?>
<?php if (can('finance.receipt.refund')): ?><a href="<?= site_url('finance/refunds/new') ?>" class="btn btn-outline-dark btn-sm"><i class="bi bi-arrow-return-left"></i> Refund</a><?php endif ?>
<?php if (can('finance.receipt.create')): ?><a href="<?= site_url('finance/receipts/new') ?>" class="btn btn-gold btn-sm"><i class="bi bi-plus-lg"></i> Record payment</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="row g-3 mb-3">
    <div class="col-sm-4"><div class="kpi-card"><div class="kpi-label">Received (filtered)</div><div class="kpi-value"><?= inr($sums['received'] ?? 0) ?></div></div></div>
    <div class="col-sm-4"><div class="kpi-card"><div class="kpi-label">Refunded</div><div class="kpi-value"><?= inr($sums['refunded'] ?? 0) ?></div></div></div>
    <div class="col-sm-4"><div class="kpi-card"><div class="kpi-label">Unapplied advances</div><div class="kpi-value"><?= inr($sums['unapplied'] ?? 0) ?></div><a class="small" href="<?= site_url('finance/receipts?unapplied=1') ?>">Show</a></div></div>
</div>
<form class="filter-bar row g-2" method="get">
    <div class="col-md-3"><input type="search" name="q" value="<?= esc($f['q']) ?>" class="form-control form-control-sm" placeholder="Voucher no., customer, UTR" aria-label="Search"></div>
    <div class="col-6 col-md-2"><select name="type" class="form-select form-select-sm" data-autosubmit aria-label="Type"><option value="">Receipts &amp; refunds</option><option value="receipt" <?= $f['type'] === 'receipt' ? 'selected' : '' ?>>Receipts</option><option value="refund" <?= $f['type'] === 'refund' ? 'selected' : '' ?>>Refunds</option></select></div>
    <div class="col-6 col-md-2"><select name="mode" class="form-select form-select-sm" data-autosubmit aria-label="Mode"><option value="">Any mode</option><?php foreach ($modes as $k => $l): ?><option value="<?= $k ?>" <?= $f['mode'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-3"><select name="account" class="form-select form-select-sm" data-autosubmit aria-label="Account"><option value="">Any account</option><?php foreach ($accounts as $a): ?><option value="<?= $a['id'] ?>" <?= $f['account'] === (int) $a['id'] ? 'selected' : '' ?>><?= esc($a['display_name']) ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-2"><select name="status" class="form-select form-select-sm" data-autosubmit aria-label="Status"><option value="">Any status</option><option value="active" <?= $f['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="void" <?= $f['status'] === 'void' ? 'selected' : '' ?>>Void</option></select></div>
    <div class="col-6 col-md-2"><input type="date" name="from" value="<?= esc($f['from'] ?? '') ?>" class="form-control form-control-sm" aria-label="From"></div>
    <div class="col-6 col-md-2"><input type="date" name="to" value="<?= esc($f['to'] ?? '') ?>" class="form-control form-control-sm" aria-label="To"></div>
    <div class="col-6 col-md-2 d-flex align-items-center"><div class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="review" value="1" id="f_review" <?= $f['review'] ? 'checked' : '' ?>><label class="form-check-label" for="f_review">Needs review</label></div></div>
    <div class="col-md-2"><button class="btn btn-dark btn-sm w-100">Filter</button></div>
</form>
<div class="table-card"><div class="table-responsive">
<table class="table table-hover align-middle small">
    <thead><tr><th>Voucher</th><th>Date</th><th>Customer</th><th>Mode</th><th>Account</th><th>Reference</th><th class="num">Amount</th><th class="num">Unapplied</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><a class="fw-semibold mono" href="<?= site_url('finance/receipts/' . $r['id']) ?>"><?= esc($r['receipt_number']) ?></a><?php if ($r['receipt_type'] === 'refund'): ?> <span class="badge text-bg-secondary">Refund</span><?php endif ?></td>
            <td><?= dmy($r['receipt_date']) ?></td>
            <td><?= esc($r['contact_name']) ?></td>
            <td><?= esc($modes[$r['mode']] ?? $r['mode']) ?><?= $r['mode'] === 'cheque' ? ' ' . status_badge($r['cheque_status']) : '' ?></td>
            <td><?= esc($r['account_name']) ?><?php if ($r['ownership'] === 'owner_personal'): ?> <span class="badge text-bg-warning" title="Owner personal account">Personal</span><?php endif ?></td>
            <td class="mono"><?= esc((string) $r['reference_no']) ?></td>
            <td class="num <?= $r['receipt_type'] === 'refund' ? 'text-danger' : '' ?>"><?= $r['receipt_type'] === 'refund' ? '−' : '' ?><?= inr($r['amount']) ?></td>
            <td class="num"><?= $r['receipt_type'] === 'receipt' && (float) $r['unapplied_amount'] > 0 ? inr($r['unapplied_amount']) : '—' ?></td>
            <td><?= status_badge($r['status']) ?><?= $r['requires_review'] ? ' <span class="badge text-bg-warning">Review</span>' : '' ?> <?= $r['reconciliation_status'] === 'matched' ? '<span class="badge text-bg-light border" title="Matched with bank statement"><i class="bi bi-check2"></i> Bank</span>' : '' ?></td>
        </tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="9"><div class="empty-state"><i class="bi bi-wallet2"></i>No receipts match these filters.</div></td></tr><?php endif ?>
    </tbody>
</table>
</div></div>
<?= view('partials/pager', ['total' => $total, 'page' => $page, 'perPage' => $perPage]) ?>
<?= $this->endSection() ?>
