<?= $this->extend('layouts/app') ?>
<?php
$d = $doc;
$isSale = in_array($d['doc_type'], ['tax_invoice', 'bill_of_supply', 'cash_memo', 'debit_note'], true);
$num = $d['document_number'] ?? $d['draft_ref'];
$msgs = $advice['messages'] ?? [];
$gstCols = match ($d['gst_type']) { 'cgst_sgst' => ['cgst' => 'CGST', 'sgst' => 'SGST'], 'cgst_utgst' => ['cgst' => 'CGST', 'utgst' => 'UTGST'], 'igst' => ['igst' => 'IGST'], default => [] };
?>
<?= $this->section('pageActions') ?>
<?php if ($canEdit): ?><a href="<?= site_url('billing/documents/' . $d['id'] . '/edit') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil"></i> Edit draft</a><?php endif ?>
<?php if ($canPdf): ?>
<div class="btn-group btn-group-sm">
    <a class="btn btn-outline-secondary" href="<?= site_url('billing/documents/' . $d['id'] . '/pdf') ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf"></i> <?= $d['status'] === 'draft' ? 'Preview PDF' : 'View PDF' ?></a>
    <a class="btn btn-outline-secondary" href="<?= site_url('billing/documents/' . $d['id'] . '/pdf?download=1') ?>"><i class="bi bi-download"></i></a>
    <?php if ($d['status'] !== 'draft'): ?><a class="btn btn-outline-secondary" href="<?= site_url('billing/documents/' . $d['id'] . '/pdf?copy=duplicate') ?>" target="_blank" rel="noopener">Duplicate copy</a><?php endif ?>
</div>
<?php endif ?>
<?php if ($d['status'] === 'finalized' && $isSale && can('finance.receipt.create') && (float) $d['balance_due'] > 0): ?><a href="<?= site_url('finance/receipts/new?contact=' . $d['contact_id'] . '&document=' . $d['id']) ?>" class="btn btn-primary btn-sm"><i class="bi bi-cash"></i> Record payment</a><?php endif ?>
<div class="dropdown"><button class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">More</button>
    <ul class="dropdown-menu dropdown-menu-end">
        <?php if (can(['billing.invoice.create_draft', 'billing.cash_memo.create_draft', 'billing.estimate.create_draft'])): ?><li><a class="dropdown-item" href="#" data-post="<?= site_url('billing/documents/' . $d['id'] . '/duplicate') ?>">Duplicate as new draft</a></li><?php endif ?>
        <?php if (in_array($d['doc_type'], ['estimate', 'quotation', 'proforma'], true) && $d['status'] !== 'converted' && can('billing.estimate.convert')): ?>
            <li><a class="dropdown-item" href="#" data-post="<?= site_url('billing/documents/' . $d['id'] . '/convert') ?>" data-field-to="tax_invoice">Convert to tax invoice</a></li>
            <li><a class="dropdown-item" href="#" data-post="<?= site_url('billing/documents/' . $d['id'] . '/convert') ?>" data-field-to="cash_memo">Convert to cash memo</a></li>
        <?php endif ?>
        <?php if ($d['status'] === 'finalized' && in_array($d['doc_type'], ['tax_invoice', 'cash_memo', 'bill_of_supply'], true) && can('billing.note.create_draft')): ?>
            <li><a class="dropdown-item" href="#" data-post="<?= site_url('billing/documents/' . $d['id'] . '/credit-note') ?>" data-field-type="credit_note">Issue credit note…</a></li>
            <li><a class="dropdown-item" href="#" data-post="<?= site_url('billing/documents/' . $d['id'] . '/credit-note') ?>" data-field-type="debit_note">Issue debit note…</a></li>
        <?php endif ?>
        <?php if ($canEdit): ?><li><hr class="dropdown-divider"></li><li><a class="dropdown-item text-danger" href="#" data-post="<?= site_url('billing/documents/' . $d['id'] . '/delete') ?>" data-confirm="Delete this draft? Drafts have no statutory number, so nothing is lost from the register." data-confirm-danger>Delete draft</a></li><?php endif ?>
        <?php if ($canCancel): ?><li><hr class="dropdown-divider"></li><li><a class="dropdown-item text-danger" href="#" data-bs-toggle="modal" data-bs-target="#cancelModal">Cancel document…</a></li><?php endif ?>
    </ul>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <?= status_badge($d['status']) ?>
    <?php if ($d['status'] === 'finalized' && $d['payment_status'] !== 'na'): ?><?= status_badge($d['payment_status']) ?><?php endif ?>
    <span class="badge badge-neutral"><?= esc(gst_type_label($d['gst_type'])) ?></span>
    <span class="badge badge-neutral">PoS <?= esc(($state['name'] ?? '—') . ' (' . ($d['place_of_supply_state_code'] ?? '—') . ')') ?></span>
    <?php if ($d['reverse_charge']): ?><span class="badge badge-warning">Reverse charge</span><?php endif ?>
    <?php if ($d['apply_signature']): ?><span class="badge badge-info"><i class="bi bi-pen"></i> Signed</span><?php endif ?>
    <?php if ($snapshot): ?><span class="small text-muted">Snapshot <?= dmy($snapshot['created_at'], true) ?> · <span class="mono"><?= esc(substr($snapshot['content_hash'], 0, 12)) ?>…</span></span><?php endif ?>
</div>
<?php if ($d['status'] === 'cancelled'): ?><div class="alert alert-secondary"><strong>Cancelled</strong> on <?= dmy($d['cancelled_at'], true) ?>: <?= esc($d['cancel_reason']) ?>. The number remains in the register and will never be reused.</div><?php endif ?>

<?php if ($d['status'] === 'draft'): ?>
<div class="card mb-3 <?= $d['tax_review_status'] === 'blocked' ? 'border-danger' : ($d['tax_review_status'] === 'needs_review' ? 'border-warning' : 'border-success') ?>">
    <div class="card-body">
        <div class="d-flex justify-content-between flex-wrap gap-2">
            <div><div class="card-section-title mb-1">Ready to finalize?</div>
                <?php foreach ($msgs as $m): ?><div class="tax-advice <?= $m['level'] === 'block' ? 'block' : ($m['level'] === 'review' ? 'warn' : '') ?> mb-1 small"><?= esc($m['text']) ?></div><?php endforeach ?>
                <?php if ($msgs === []): ?><div class="small text-success"><i class="bi bi-check-circle"></i> Tax type and place of supply check out.</div><?php endif ?>
            </div>
            <?php if ($canFinalize): ?>
            <div><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#finalizeModal" <?= $d['tax_review_status'] === 'blocked' ? 'disabled' : '' ?>><i class="bi bi-lock"></i> Finalize &amp; assign number</button></div>
            <?php else: ?><div class="small text-muted">You can save and preview drafts; a user with finalize permission must issue it.</div><?php endif ?>
        </div>
    </div>
</div>
<?php elseif ($d['tax_review_status'] === 'resolved'): ?>
<div class="alert alert-secondary border small"><i class="bi bi-person-check"></i> Tax review resolved: <?= esc($d['tax_review_reason']) ?> (<?= dmy($d['tax_reviewed_at'], true) ?>)</div>
<?php endif ?>

<div class="row g-3">
<div class="col-xl-8">
    <div class="card mb-3"><div class="card-body">
        <div class="row small">
            <div class="col-md-6"><div class="card-section-title mb-1">Bill to</div>
                <div class="fw-semibold"><a href="<?= can('contacts.view_all') ? site_url('contacts/' . $d['contact_id']) : '#' ?>"><?= esc($d['bill_to_name']) ?></a></div>
                <div class="u-ws-pre-line"><?= esc((string) $d['bill_to_address']) ?></div>
                <div>GSTIN: <span class="mono"><?= esc($d['bill_to_gstin'] ?? 'Unregistered') ?></span> · State <?= esc((string) $d['bill_to_state_code']) ?></div></div>
            <div class="col-md-6"><dl class="row mb-0">
                <dt class="col-5 fw-normal text-muted">Number</dt><dd class="col-7 mono fw-semibold"><?= esc($num) ?></dd>
                <dt class="col-5 fw-normal text-muted">Issue date</dt><dd class="col-7"><?= dmy($d['issue_date']) ?></dd>
                <dt class="col-5 fw-normal text-muted">Due date</dt><dd class="col-7"><?= dmy($d['due_date']) ?></dd>
                <?php if ($d['service_period_from']): ?><dt class="col-5 fw-normal text-muted">Service period</dt><dd class="col-7"><?= dmy($d['service_period_from']) ?> – <?= dmy($d['service_period_to']) ?></dd><?php endif ?>
                <?php if ($d['po_number']): ?><dt class="col-5 fw-normal text-muted">PO</dt><dd class="col-7"><?= esc($d['po_number']) ?></dd><?php endif ?>
                <?php if ($original): ?><dt class="col-5 fw-normal text-muted">Against</dt><dd class="col-7"><a href="<?= site_url('billing/documents/' . $original['id']) ?>"><?= esc($original['document_number']) ?></a> (<?= dmy($original['issue_date']) ?>)</dd><?php endif ?>
                <?php if ($d['einvoice_irn']): ?><dt class="col-5 fw-normal text-muted">IRN</dt><dd class="col-7 mono small text-break"><?= esc($d['einvoice_irn']) ?></dd><?php endif ?>
            </dl></div>
        </div>
    </div></div>

    <div class="table-card mb-3"><div class="table-responsive">
        <table class="table table-sm small mb-0 align-middle">
            <thead><tr><th>#</th><th>Item</th><th>SAC</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Taxable</th><?php foreach ($gstCols as $k => $lbl): ?><th class="num"><?= $lbl ?></th><?php endforeach ?><th class="num">Total</th></tr></thead>
            <tbody>
            <?php foreach ($lines as $l): ?>
                <tr><td><?= (int) $l['line_no'] ?></td>
                    <td><strong><?= esc($l['item_name']) ?></strong><?php if ($l['description']): ?><div class="text-muted"><?= esc(mb_strimwidth($l['description'], 0, 140, '…')) ?></div><?php endif ?><?php if ($l['tax_treatment'] !== 'regular'): ?><span class="badge badge-warning"><?= esc($l['tax_treatment']) ?></span><?php endif ?></td>
                    <td class="mono"><?= esc((string) $l['sac_code']) ?></td>
                    <td class="num"><?= esc(rtrim(rtrim($l['qty'], '0'), '.')) ?> <?= esc((string) $l['unit']) ?></td>
                    <td class="num"><?= inr($l['unit_rate']) ?></td>
                    <td class="num"><?= inr($l['taxable_value']) ?></td>
                    <?php foreach ($gstCols as $k => $lbl): ?><td class="num"><?= (float) $l[$k . '_amount'] > 0 ? \App\Libraries\Dec::rateLabel($l[$k . '_rate']) . '% · ' . inr($l[$k . '_amount']) : '—' ?></td><?php endforeach ?>
                    <td class="num fw-semibold"><?= inr($l['line_total']) ?></td></tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div></div>

    <?php if ($d['status'] === 'finalized' && $company && $company['einvoice_applicable'] && ! $d['einvoice_irn'] && in_array($d['doc_type'], ['tax_invoice', 'credit_note', 'debit_note'], true) && $d['bill_to_gstin'] && can(['billing.invoice.finalize', 'billing.issue_credit_note'])): ?>
    <div class="card mb-3 border-warning"><div class="card-body small">
        <strong>E-invoice:</strong> no IRP integration is configured. Generate the IRN on the government portal, then record it here.
        <form method="post" action="<?= site_url('billing/documents/' . $d['id'] . '/irn') ?>" class="row g-2 mt-1"><?= csrf_field() ?>
            <div class="col-md-6"><input name="irn" class="form-control form-control-sm mono" placeholder="64-character IRN" maxlength="64" aria-label="IRN"></div>
            <div class="col-md-3"><input name="ack_no" class="form-control form-control-sm" placeholder="Ack no." aria-label="Ack number"></div>
            <div class="col-md-2"><input type="date" name="ack_date" class="form-control form-control-sm" aria-label="Ack date"></div>
            <div class="col-md-1"><button class="btn btn-sm btn-primary">Save</button></div></form>
    </div></div>
    <?php endif ?>

    <?php if ($payments !== [] || ($isSale && can('finance.receipt.view') && $d['status'] === 'finalized')): ?>
    <div class="table-card mb-3"><div class="p-3 border-bottom"><strong>Payments applied</strong></div>
        <table class="table table-sm small mb-0"><thead><tr><th>Receipt</th><th>Date</th><th>Mode</th><th class="num">Amount</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($payments as $p): ?><tr class="<?= $p['reversed_at'] ? 'text-muted text-decoration-line-through' : '' ?>"><td><a href="<?= site_url('finance/receipts/' . $p['receipt_id']) ?>"><?= esc($p['receipt_number']) ?></a></td><td><?= dmy($p['receipt_date']) ?></td><td><?= esc(strtoupper($p['mode'])) ?></td><td class="num"><?= inr($p['amount']) ?></td><td><?= status_badge($p['reversed_at'] ? 'void' : $p['status']) ?></td></tr><?php endforeach ?>
        <?php if ($payments === []): ?><tr><td colspan="5" class="text-muted">No payments yet.</td></tr><?php endif ?>
        </tbody></table></div>
    <?php endif ?>

    <?php if ($notes !== []): ?>
    <div class="table-card mb-3"><div class="p-3 border-bottom"><strong>Credit / debit notes</strong></div>
        <table class="table table-sm small mb-0"><tbody>
        <?php foreach ($notes as $n): ?><tr><td><a href="<?= site_url('billing/documents/' . $n['id']) ?>"><?= esc(doc_type_label($n['doc_type']) . ' ' . ($n['document_number'] ?? $n['draft_ref'])) ?></a></td><td><?= dmy($n['issue_date']) ?></td><td class="num"><?= inr($n['grand_total']) ?></td><td><?= status_badge($n['status']) ?></td></tr><?php endforeach ?>
        </tbody></table></div>
    <?php endif ?>
</div>

<div class="col-xl-4">
    <div class="totals-box mb-3">
        <div class="row-t"><span>Taxable value</span><span><?= inr($d['taxable_total']) ?></span></div>
        <?php foreach ($taxes as $t): ?><div class="row-t"><span><?= esc($t['tax_type']) ?> @ <?= \App\Libraries\Dec::rateLabel($t['rate']) ?>%</span><span><?= inr($t['tax_amount']) ?></span></div><?php endforeach ?>
        <?php if ((float) $d['round_off'] != 0): ?><div class="row-t"><span>Round-off</span><span><?= inr($d['round_off']) ?></span></div><?php endif ?>
        <div class="row-t grand"><span>Grand total</span><span><?= inr($d['grand_total']) ?></span></div>
        <?php if ($d['status'] === 'finalized' && $isSale): ?>
            <div class="row-t mt-2"><span>Paid</span><span><?= inr($d['amount_paid']) ?></span></div>
            <?php if ((float) $d['amount_credited'] > 0): ?><div class="row-t"><span>Credited</span><span><?= inr($d['amount_credited']) ?></span></div><?php endif ?>
            <div class="row-t fw-bold"><span>Balance due</span><span class="<?= (float) $d['balance_due'] > 0 ? 'text-danger' : 'text-success' ?>"><?= inr($d['balance_due']) ?></span></div>
        <?php endif ?>
        <div class="small text-muted mt-2"><?= esc((string) $d['amount_in_words']) ?></div>
    </div>
    <div class="card"><div class="card-body small">
        <div class="card-section-title">History</div>
        <ul class="timeline">
            <?php foreach ($history as $h): ?><li><span class="tl-dot"></span><div><?= esc($h['summary']) ?></div><div class="tl-time"><?= dmy($h['occurred_at'], true) ?> · <?= esc($h['full_name'] ?? 'system') ?></div></li><?php endforeach ?>
        </ul>
    </div></div>
</div>
</div>

<?php if ($canFinalize): ?>
<div class="modal fade" id="finalizeModal" tabindex="-1" aria-labelledby="finalizeTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<form method="post" action="<?= site_url('billing/documents/' . $d['id'] . '/finalize') ?>">
    <?= csrf_field() ?>
    <div class="modal-header"><h2 class="modal-title fs-5" id="finalizeTitle">Finalize <?= esc(doc_type_label($d['doc_type'])) ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body small">
        <p>The next number in the <strong><?= esc(doc_type_label($d['doc_type'])) ?></strong> series will be assigned. After this the document cannot be edited or deleted — corrections need a credit/debit note.</p>
        <p>Total: <strong><?= inr($d['grand_total']) ?></strong> · <?= esc(gst_type_label($d['gst_type'])) ?> · PoS <?= esc((string) $d['place_of_supply_state_code']) ?></p>
        <?php if ($d['tax_review_status'] === 'needs_review'): ?>
            <?php if ($canOverride): ?>
                <label class="form-label required" for="tax_review_reason">Tax review decision &amp; reason</label>
                <textarea class="form-control mb-2" id="tax_review_reason" name="tax_review_reason" rows="3" required minlength="10" placeholder="e.g. Accountant confirmed place of supply 08 because…"></textarea>
            <?php else: ?><div class="alert alert-warning">This document needs a tax review by an authorised user before it can be finalized.</div><?php endif ?>
        <?php endif ?>
        <?php if (in_array($d['doc_type'], ['credit_note', 'debit_note'], true)): ?>
            <label class="form-label" for="reason_code">Reason</label>
            <select class="form-select mb-2" id="reason_code" name="reason_code"><option value="post_sale_discount">Post-sale discount</option><option value="price_revision">Price revision</option><option value="service_deficiency">Service deficiency</option><option value="excess_billing">Excess billing</option><option value="short_billing">Short billing</option><option value="cancellation">Cancellation of supply</option><option value="other">Other</option></select>
            <input class="form-control mb-2" name="reason_text" placeholder="Details" aria-label="Reason details">
        <?php endif ?>
        <?php if ($canSign): ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="apply_signature" value="1" id="apply_signature"><label class="form-check-label" for="apply_signature">Apply authorised signature/stamp (logged)</label></div>
        <?php else: ?><div class="text-muted">Signature will not be applied (not permitted by the signature policy for you or no signature uploaded).</div><?php endif ?>
        <?php if (in_array($d['doc_type'], ['cash_memo', 'tax_invoice', 'bill_of_supply'], true) && $cashAccounts !== []): ?>
            <hr><div class="form-check"><input class="form-check-input" type="checkbox" name="paid_at_counter" value="1" id="paid_at_counter"><label class="form-check-label" for="paid_at_counter">Customer paid in full now (records one receipt)</label></div>
            <div class="row g-2 mt-1"><div class="col-6"><select class="form-select form-select-sm" name="paid_mode" aria-label="Mode"><option value="cash">Cash</option><option value="upi">UPI</option><option value="card">Card</option><option value="bank_transfer">Bank transfer</option></select></div>
                <div class="col-6"><select class="form-select form-select-sm" name="paid_account_id" aria-label="Received into"><?php foreach ($cashAccounts as $a): ?><option value="<?= $a['id'] ?>"><?= esc($a['display_name']) ?></option><?php endforeach ?></select></div></div>
        <?php endif ?>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Finalize now</button></div>
</form></div></div></div>
<?php endif ?>

<?php if ($canCancel): ?>
<div class="modal fade" id="cancelModal" tabindex="-1" aria-labelledby="cancelTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<form method="post" action="<?= site_url('billing/documents/' . $d['id'] . '/cancel') ?>">
    <?= csrf_field() ?>
    <div class="modal-header"><h2 class="modal-title fs-5" id="cancelTitle">Cancel <?= esc($num) ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body small"><p>Cancellation reverses the accounting entry. The number stays in the register as <em>cancelled</em> and is never reused. If the invoice was already reported in GSTR-1, issue a credit note instead — check with your accountant.</p>
        <label class="form-label required" for="cancel_reason">Reason</label><textarea class="form-control" id="cancel_reason" name="reason" rows="3" required minlength="10"></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Back</button><button type="submit" class="btn btn-danger">Cancel document</button></div>
</form></div></div></div>
<?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="<?= asset('js/billing-show.js') ?>"></script><?= $this->endSection() ?>
