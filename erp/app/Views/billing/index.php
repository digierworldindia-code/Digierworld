<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if ($canExport): ?>
<div class="btn-group btn-group-sm"><a class="btn btn-outline-dark" href="<?= site_url('billing/documents/export?' . http_build_query($f + ['format' => 'xlsx'])) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a><a class="btn btn-outline-dark" href="<?= site_url('billing/documents/export?' . http_build_query($f + ['format' => 'csv'])) ?>">CSV</a></div>
<?php endif ?>
<?php if (can(['billing.invoice.create_draft', 'billing.cash_memo.create_draft', 'billing.estimate.create_draft'])): ?>
<a href="<?= site_url('billing/documents/new' . ($f['type'] && $f['type'] !== 'credit_note' ? '?type=' . $f['type'] : '')) ?>" class="btn btn-gold btn-sm"><i class="bi bi-plus-lg"></i> Create document</a>
<?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<ul class="nav nav-tabs mb-3 small">
    <?php foreach (['' => 'All', 'tax_invoice' => 'Tax invoices', 'cash_memo' => 'Cash memos', 'bill_of_supply' => 'Bills of supply', 'estimate' => 'Estimates', 'credit_note' => 'Credit/Debit notes'] as $k => $l): ?>
        <li class="nav-item"><a class="nav-link <?= $f['type'] === $k ? 'active' : '' ?>" href="<?= site_url('billing/documents' . ($k !== '' ? '?type=' . $k : '')) ?>"><?= $l ?></a></li>
    <?php endforeach ?>
</ul>
<form class="filter-bar row g-2" method="get">
    <input type="hidden" name="type" value="<?= esc($f['type']) ?>">
    <div class="col-md-3"><input type="search" name="q" value="<?= esc($f['q']) ?>" class="form-control form-control-sm" placeholder="Number, customer, GSTIN" aria-label="Search"></div>
    <div class="col-6 col-md-2"><select name="status" class="form-select form-select-sm" data-autosubmit aria-label="Status"><option value="">Any status</option><?php foreach (['draft', 'finalized', 'cancelled', 'converted'] as $s): ?><option value="<?= $s ?>" <?= $f['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-2"><select name="payment" class="form-select form-select-sm" data-autosubmit aria-label="Payment"><option value="">Any payment</option><?php foreach (['unpaid' => 'Unpaid', 'partial' => 'Partly paid', 'paid' => 'Paid', 'overdue' => 'Overdue'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['payment'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-2"><select name="gst" class="form-select form-select-sm" data-autosubmit aria-label="GST type"><option value="">Any GST type</option><?php foreach (['cgst_sgst' => 'CGST+SGST', 'cgst_utgst' => 'CGST+UTGST', 'igst' => 'IGST', 'none' => 'No GST'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['gst'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-3"><select name="service" class="form-select form-select-sm" data-autosubmit aria-label="Service"><option value="">Any service</option><?php foreach ($services as $s): ?><option value="<?= $s['id'] ?>" <?= $f['service'] === (int) $s['id'] ? 'selected' : '' ?>><?= esc($s['name']) ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-2"><input type="date" name="from" value="<?= esc($f['from'] ?? '') ?>" class="form-control form-control-sm" aria-label="From"></div>
    <div class="col-6 col-md-2"><input type="date" name="to" value="<?= esc($f['to'] ?? '') ?>" class="form-control form-control-sm" aria-label="To"></div>
    <div class="col-md-2"><button class="btn btn-dark btn-sm w-100">Filter</button></div>
    <?php if ($sums !== null): ?><div class="col-md-6 small text-md-end align-self-center">Finalized value: <strong><?= inr($sums['total_value'] ?? 0) ?></strong> · Balance due: <strong><?= inr($sums['total_due'] ?? 0) ?></strong></div><?php endif ?>
</form>
<div class="table-card"><div class="table-responsive">
<table class="table table-hover align-middle small">
    <thead><tr><th>Number</th><th>Type</th><th>Date</th><th>Customer</th><th>GST</th><th class="num">Total</th><th class="num">Balance</th><th>Status</th><th>Created by</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $overdue = $r['status'] === 'finalized' && (float) $r['balance_due'] > 0 && $r['due_date'] && $r['due_date'] < date('Y-m-d'); ?>
        <tr>
            <td><a href="<?= site_url('billing/documents/' . $r['id']) ?>" class="fw-semibold mono"><?= esc($r['document_number'] ?? $r['draft_ref']) ?></a></td>
            <td><?= esc(doc_type_label($r['doc_type'])) ?></td>
            <td><?= dmy($r['issue_date']) ?></td>
            <td><?= esc($r['bill_to_name']) ?><?php if ($r['bill_to_gstin']): ?><div class="text-muted mono"><?= esc($r['bill_to_gstin']) ?></div><?php endif ?></td>
            <td><?= esc(['cgst_sgst' => 'CGST+SGST', 'cgst_utgst' => 'CGST+UTGST', 'igst' => 'IGST', 'none' => '—'][$r['gst_type'] ?? 'none'] ?? '—') ?> <span class="text-muted"><?= esc((string) $r['place_of_supply_state_code']) ?></span></td>
            <td class="num"><?= inr($r['grand_total']) ?></td>
            <td class="num <?= $overdue ? 'text-danger fw-semibold' : '' ?>"><?= $r['status'] === 'finalized' && $r['payment_status'] !== 'na' ? inr($r['balance_due']) : '—' ?></td>
            <td><?= status_badge($r['status']) ?> <?= $r['status'] === 'finalized' && $r['payment_status'] !== 'na' ? status_badge($overdue ? 'overdue' : $r['payment_status']) : '' ?><?= $r['status'] === 'draft' && in_array($r['tax_review_status'], ['needs_review', 'blocked'], true) ? ' ' . status_badge($r['tax_review_status']) : '' ?></td>
            <td class="text-muted"><?= esc((string) $r['creator']) ?></td>
        </tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="9"><div class="empty-state"><i class="bi bi-receipt"></i>No documents match these filters.</div></td></tr><?php endif ?>
    </tbody>
</table>
</div></div>
<?= view('partials/pager', ['total' => $total, 'page' => $page, 'perPage' => $perPage]) ?>
<?= $this->endSection() ?>
