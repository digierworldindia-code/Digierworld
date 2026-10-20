<?= $this->extend('layouts/app') ?>
<?php $isRefund = $r['receipt_type'] === 'refund'; $active = $r['status'] === 'active'; ?>
<?= $this->section('pageActions') ?>
<div class="btn-group btn-group-sm">
    <a class="btn btn-outline-secondary" href="<?= site_url('finance/receipts/' . $r['id'] . '/pdf') ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf"></i> Voucher PDF</a>
    <a class="btn btn-outline-secondary" href="<?= site_url('finance/receipts/' . $r['id'] . '/pdf?download=1') ?>" aria-label="Download PDF"><i class="bi bi-download"></i></a>
</div>
<?php if ($active && ! $isRefund && (float) $r['unapplied_amount'] > 0 && can('finance.receipt.refund')): ?>
    <a class="btn btn-outline-secondary btn-sm" href="<?= site_url('finance/refunds/new?contact=' . $r['contact_id'] . '&receipt=' . $r['id']) ?>"><i class="bi bi-arrow-return-left"></i> Refund advance</a>
<?php endif ?>
<?php if ($active && can('finance.receipt.void')): ?>
    <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#voidModal"><i class="bi bi-x-octagon"></i> Void</button>
<?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="d-flex flex-wrap gap-2 mb-3 small">
    <?= status_badge($r['status']) ?>
    <?php if ($r['requires_review']): ?><span class="badge badge-warning">Owner personal account — review</span><?php endif ?>
    <?php if ($r['mode'] === 'cheque'): ?><?= status_badge($r['cheque_status']) ?><?php endif ?>
    <span class="badge badge-neutral">Bank: <?= esc($r['reconciliation_status']) ?></span>
</div>
<?php if (! $active): ?><div class="alert alert-secondary"><strong>Void</strong> on <?= dmy($r['voided_at'], true) ?>: <?= esc((string) $r['void_reason']) ?>. The accounting entry was reversed; the voucher number is never reused.</div><?php endif ?>
<div class="row g-3">
<div class="col-lg-8">
    <div class="card mb-3"><div class="card-body">
        <div class="row g-3 small">
            <div class="col-sm-6"><div class="text-muted">Customer</div><a class="fw-semibold" href="<?= site_url('contacts/' . $r['contact_id']) ?>"><?= esc($r['contact_name']) ?></a><div class="mono text-muted"><?= esc((string) $r['contact_gstin']) ?></div></div>
            <div class="col-sm-3"><div class="text-muted">Date</div><?= dmy($r['receipt_date']) ?></div>
            <div class="col-sm-3"><div class="text-muted">Mode</div><?= esc($modes[$r['mode']] ?? $r['mode']) ?></div>
            <div class="col-sm-6"><div class="text-muted"><?= $isRefund ? 'Paid from' : 'Received into' ?></div><?= esc($r['account_name']) ?></div>
            <div class="col-sm-3"><div class="text-muted">Reference / UTR</div><span class="mono"><?= esc((string) $r['reference_no']) ?: '—' ?></span></div>
            <div class="col-sm-3"><div class="text-muted">Recorded by</div><?= esc((string) $r['creator']) ?></div>
            <?php if ($r['notes']): ?><div class="col-12"><div class="text-muted">Notes</div><?= esc($r['notes']) ?></div><?php endif ?>
            <?php if ($source): ?><div class="col-12"><div class="text-muted">Refund of advance</div><a href="<?= site_url('finance/receipts/' . $source['id']) ?>"><?= esc($source['receipt_number']) ?></a></div><?php endif ?>
        </div>
    </div></div>

    <?php if (! $isRefund): ?>
    <div class="table-card mb-3"><div class="card-header"><strong>Applied to</strong></div><div class="table-responsive">
        <table class="table align-middle small mb-0"><thead><tr><th>Document</th><th>Date</th><th class="num">Applied</th><th class="num">Balance now</th><th>Applied on</th><th></th></tr></thead><tbody>
        <?php foreach ($allocations as $a): ?>
            <tr class="<?= $a['reversed_at'] ? 'text-decoration-line-through text-muted' : '' ?>">
                <td><a class="mono" href="<?= site_url('billing/documents/' . $a['document_id']) ?>"><?= esc($a['document_number']) ?></a> <span class="text-muted"><?= esc(doc_type_label($a['doc_type'])) ?></span></td>
                <td><?= dmy($a['issue_date']) ?></td><td class="num"><?= inr($a['amount']) ?></td><td class="num"><?= inr($a['balance_due']) ?></td>
                <td><?= dmy($a['allocated_at'], true) ?></td><td><?= $a['reversed_at'] ? 'reversed' : '' ?></td>
            </tr>
        <?php endforeach ?>
        <?php if ($allocations === []): ?><tr><td colspan="6" class="text-muted">Not applied to any invoice yet.</td></tr><?php endif ?>
        </tbody></table>
    </div></div>

    <?php if ($openDocs !== []): ?>
    <form method="post" action="<?= site_url('finance/receipts/' . $r['id'] . '/apply') ?>" class="card mb-3"><div class="card-body">
        <?= csrf_field() ?>
        <div class="card-section-title">Apply advance (<?= inr($r['unapplied_amount']) ?> available)</div>
        <div class="table-responsive"><table class="table table-sm align-middle small"><thead><tr><th>Document</th><th>Date</th><th class="num">Balance due</th><th class="u-w-150px num">Apply</th></tr></thead><tbody>
            <?php foreach ($openDocs as $d): ?>
            <tr><td class="mono"><?= esc($d['document_number']) ?></td><td><?= dmy($d['issue_date']) ?></td><td class="num"><?= inr($d['balance_due']) ?></td>
                <td><input class="form-control form-control-sm text-end" name="alloc[<?= (int) $d['id'] ?>]" inputmode="decimal" aria-label="Apply to <?= esc($d['document_number']) ?>"></td></tr>
            <?php endforeach ?>
        </tbody></table></div>
        <button class="btn btn-primary btn-sm" type="submit">Apply advance</button>
    </div></form>
    <?php endif ?>
    <?php endif ?>

    <?php if ($refunds !== []): ?>
    <div class="table-card mb-3"><div class="card-header"><strong>Refunds from this receipt</strong></div><table class="table small mb-0"><tbody>
        <?php foreach ($refunds as $f): ?><tr><td><a class="mono" href="<?= site_url('finance/receipts/' . $f['id']) ?>"><?= esc($f['receipt_number']) ?></a></td><td><?= dmy($f['receipt_date']) ?></td><td class="num"><?= inr($f['amount']) ?></td><td><?= status_badge($f['status']) ?></td></tr><?php endforeach ?>
    </tbody></table></div>
    <?php endif ?>

    <?php if ($active && $r['mode'] === 'cheque' && $r['cheque_status'] === 'pending'): ?>
    <form method="post" action="<?= site_url('finance/receipts/' . $r['id'] . '/cheque') ?>" class="card mb-3"><div class="card-body d-flex flex-wrap gap-2 align-items-end">
        <?= csrf_field() ?>
        <div class="flex-grow-1"><label class="form-label small" for="cheque_note">Cheque status note</label><input class="form-control form-control-sm" id="cheque_note" name="note" placeholder="e.g. Bank memo / return reason"></div>
        <button class="btn btn-outline-success btn-sm" name="status" value="cleared">Mark cleared</button>
        <?php if (can('finance.receipt.void')): ?><button class="btn btn-outline-danger btn-sm" name="status" value="bounced">Mark bounced (voids receipt)</button><?php endif ?>
    </div></form>
    <?php endif ?>
</div>
<div class="col-lg-4">
    <div class="card totals-box mb-3"><div class="card-body">
        <div class="d-flex justify-content-between fs-5"><span><?= $isRefund ? 'Refunded' : 'Received' ?></span><strong><?= inr($r['amount']) ?></strong></div>
        <?php if (! $isRefund): ?>
        <div class="d-flex justify-content-between"><span>Applied</span><span><?= inr($r['allocated_amount']) ?></span></div>
        <div class="d-flex justify-content-between"><span>Unapplied (advance)</span><strong><?= inr($r['unapplied_amount']) ?></strong></div>
        <?php endif ?>
        <div class="small text-muted mt-2"><?= esc(\App\Libraries\AmountInWords::inr($r['amount'])) ?></div>
        <?php if ($journal && can('finance.ledger.view')): ?><div class="small mt-2">Journal: <a href="<?= site_url('finance/ledger/entries/' . $journal['id']) ?>" class="mono"><?= esc($journal['entry_no']) ?></a></div><?php endif ?>
    </div></div>
    <div class="card"><div class="card-body">
        <div class="card-section-title">History</div>
        <ul class="timeline small"><?php foreach ($history as $h): ?><li><div><?= esc($h['summary'] ?: $h['action']) ?></div><div class="text-muted"><?= dmy($h['occurred_at'], true) ?> · <?= esc((string) $h['full_name']) ?></div></li><?php endforeach ?></ul>
    </div></div>
</div>
</div>

<?php if ($active && can('finance.receipt.void')): ?>
<div class="modal fade" id="voidModal" tabindex="-1" aria-labelledby="voidTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<form method="post" action="<?= site_url('finance/receipts/' . $r['id'] . '/void') ?>">
    <?= csrf_field() ?>
    <div class="modal-header"><h2 class="modal-title fs-5" id="voidTitle">Void <?= esc($r['receipt_number']) ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body small"><p>The accounting entry is reversed and any invoices it paid are re-opened. The voucher number stays in the register as void.</p>
        <label class="form-label required" for="void_reason">Reason</label><textarea class="form-control" id="void_reason" name="reason" rows="3" required minlength="5"></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Back</button><button type="submit" class="btn btn-danger">Void receipt</button></div>
</form></div></div></div>
<?php endif ?>
<?= $this->endSection() ?>
