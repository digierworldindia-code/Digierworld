<?= $this->extend('layouts/app') ?>
<?php $v = static fn (string $k, $d = '') => esc((string) (old($k) ?? $d)); ?>
<?= $this->section('content') ?>
<?php if ($contact === null): ?>
<form method="get" class="card"><div class="card-body">
    <div class="card-section-title">Choose customer</div>
    <p class="small text-muted">Refunds are paid from an unapplied advance, or from a credit balance created by credit notes.</p>
    <div class="row g-2"><div class="col-md-6">
        <select class="form-select" name="contact" required aria-label="Customer">
            <option value="">— customers with an advance or credit —</option>
            <?php foreach ($candidates as $c): ?>
                <option value="<?= $c['id'] ?>"><?= esc($c['name']) ?></option>
            <?php endforeach ?>
        </select></div>
        <div class="col-md-3"><button class="btn btn-primary">Continue</button></div></div>
</div></form>
<?php else: ?>
<form method="post" action="<?= site_url('finance/refunds') ?>" class="card"><div class="card-body">
    <?= csrf_field() ?>
    <input type="hidden" name="contact_id" value="<?= (int) $contact['id'] ?>">
    <div class="card-section-title">Refund to <?= esc($contact['name']) ?></div>
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label required" for="from_receipt_id">Refund from</label>
            <select class="form-select" id="from_receipt_id" name="from_receipt_id">
                <?php foreach ($advances as $a): ?><option value="<?= $a['id'] ?>" <?= (int) $a['id'] === $fromReceipt ? 'selected' : '' ?>>Advance <?= esc($a['receipt_number']) ?> (<?= dmy($a['receipt_date']) ?>) — <?= inr($a['unapplied_amount']) ?> unapplied</option><?php endforeach ?>
                <option value="0" <?= $advances === [] ? 'selected' : '' ?>>Credit balance from credit notes — <?= inr($credit) ?> available</option>
            </select></div>
        <div class="col-md-3"><label class="form-label required" for="amount">Amount (₹)</label><input class="form-control" id="amount" name="amount" inputmode="decimal" value="<?= $v('amount') ?>" required></div>
        <div class="col-md-3"><label class="form-label required" for="refund_date">Date</label><input type="date" class="form-control" id="refund_date" name="refund_date" value="<?= $v('refund_date', date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>" required></div>
        <div class="col-md-3"><label class="form-label required" for="mode">Mode</label><select class="form-select" id="mode" name="mode" required><?php foreach ($modes as $k => $l): ?><option value="<?= $k ?>" <?= old('mode') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></div>
        <div class="col-md-5"><label class="form-label required" for="bank_cash_account_id">Paid from account</label><select class="form-select" id="bank_cash_account_id" name="bank_cash_account_id" required><?php foreach ($accounts as $a): ?><option value="<?= $a['id'] ?>"><?= esc($a['display_name']) ?></option><?php endforeach ?></select></div>
        <div class="col-md-4"><label class="form-label" for="notes">Notes</label><input class="form-control" id="notes" name="notes" value="<?= $v('notes') ?>" maxlength="500"></div>
    </div>
    <p class="small text-muted mt-3 mb-0">A refund reduces the customer's advance (or credit balance) and the cash/bank balance. It is not an expense.</p>
</div>
<div class="card-footer text-end"><a href="<?= site_url('finance/receipts') ?>" class="btn btn-outline-secondary">Cancel</a> <button type="submit" class="btn btn-primary">Record refund</button></div>
</form>
<?php endif ?>
<?= $this->endSection() ?>
