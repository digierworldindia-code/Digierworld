<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Invoices & payments', 'subtitle' => 'Membership, service and proforma invoices issued to your company.']) ?>
<?php if (! $escrow): ?><div class="alert alert-light border small"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Escrow-supported payments will be available once BearingCave's licensed escrow/payment partner is configured. Until then, payments are by bank transfer confirmed by BearingCave finance.</div><?php endif ?>
<div class="bc-card mb-4">
    <div class="bc-card-header"><h2>Invoices</h2></div>
    <?php if (! $invoices): ?><?= empty_state('bi-receipt', 'No invoices yet') ?><?php else: ?>
    <div class="table-responsive"><table class="table table-bc table-stack mb-0">
        <thead><tr><th>Invoice</th><th>Type</th><th>Issued</th><th>Due</th><th class="text-end">Total</th><th>Status</th><th></th></tr></thead>
        <tbody><?php foreach ($invoices as $i): ?><tr>
            <td data-label="Invoice" class="part-no"><?= esc($i['invoice_number']) ?></td><td data-label="Type"><?= esc(ucfirst($i['invoice_type'])) ?></td>
            <td data-label="Issued"><?= fdate($i['issued_at']) ?></td><td data-label="Due"><?= fdate($i['due_at']) ?></td>
            <td data-label="Total" class="text-end fw-semibold"><?= money($i['total'], $i['currency']) ?></td><td data-label="Status"><?= status_badge($i['status']) ?></td>
            <td><a class="btn btn-sm btn-light" href="<?= site_url($area . '/billing/invoices/' . $i['id']) ?>"><?= $i['status'] === 'issued' ? 'View & pay' : 'View' ?></a></td>
        </tr><?php endforeach ?></tbody>
    </table></div>
    <?= $pager->links() ?>
    <?php endif ?>
</div>
<div class="bc-card">
    <div class="bc-card-header"><h2>Recent payments</h2></div>
    <?php if (! $payments): ?><?= empty_state('bi-credit-card', 'No payments recorded') ?><?php else: ?>
    <div class="table-responsive"><table class="table table-bc table-stack mb-0">
        <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($payments as $p): ?><tr><td data-label="Date"><?= fdt($p['paid_at'] ?? $p['created_at']) ?></td><td data-label="Method"><?= esc(str_replace('_', ' ', $p['provider'])) ?><?= $p['is_sandbox'] ? ' <span class="badge-sample">SANDBOX</span>' : '' ?></td><td data-label="Reference" class="part-no small"><?= esc($p['provider_reference'] ?? '—') ?></td><td data-label="Amount" class="text-end"><?= money($p['amount'], $p['currency']) ?></td><td data-label="Status"><?= status_badge($p['status']) ?></td></tr><?php endforeach ?></tbody>
    </table></div>
    <?php endif ?>
</div>
<?= $this->endSection() ?>
