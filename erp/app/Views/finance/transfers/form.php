<?= $this->extend('layouts/app') ?>
<?php
$v = static fn (string $k, $d = '') => esc((string) (old($k) ?? $d));
$opts = static function (int $sel) use ($accounts): string {
    $h = '<option value="">— choose —</option>';
    foreach ($accounts as $a) {
        $h .= '<option value="' . $a['id'] . '"' . ($sel === (int) $a['id'] ? ' selected' : '') . '>' . esc($a['display_name']) . ' (balance ' . inr($a['balance']) . ')</option>';
    }

    return $h;
};
$errs = flash_errors();
?>
<?= $this->section('content') ?>
<form method="post" action="<?= site_url('finance/transfers') ?>" class="u-mxw-820px card"><div class="card-body">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label required" for="from_account_id">From (money leaves)</label><select class="form-select<?= is_invalid('from_account_id') ?>" id="from_account_id" name="from_account_id" required><?= $opts((int) (old('from_account_id') ?? $fromId)) ?></select><?= field_error('from_account_id') ?></div>
        <div class="col-md-6"><label class="form-label required" for="to_account_id">To (money arrives)</label><select class="form-select<?= is_invalid('to_account_id') ?>" id="to_account_id" name="to_account_id" required><?= $opts((int) (old('to_account_id') ?? $toId)) ?></select><?= field_error('to_account_id') ?></div>
        <div class="col-md-4"><label class="form-label required" for="amount">Amount (₹)</label><input class="form-control<?= is_invalid('amount') ?>" id="amount" name="amount" inputmode="decimal" value="<?= $v('amount') ?>" required><?= field_error('amount') ?></div>
        <div class="col-md-4"><label class="form-label required" for="transfer_date">Date</label><input type="date" class="form-control<?= is_invalid('transfer_date') ?>" id="transfer_date" name="transfer_date" value="<?= $v('transfer_date', date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>" required><?= field_error('transfer_date') ?></div>
        <div class="col-md-4"><label class="form-label" for="reference_no">Deposit slip / UTR</label><input class="form-control" id="reference_no" name="reference_no" value="<?= $v('reference_no') ?>" maxlength="80"></div>
        <div class="col-12"><label class="form-label" for="notes">Notes</label><input class="form-control" id="notes" name="notes" value="<?= $v('notes') ?>" maxlength="500"></div>
        <?php if (isset($errs['amount']) && str_contains($errs['amount'], 'cash balance') && can('finance.transfer.void')): ?>
        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="allow_negative" value="1" id="allow_negative"><label class="form-check-label" for="allow_negative">I confirm cash receipts will be recorded separately; allow cash in hand to go negative</label></div></div>
        <?php endif ?>
    </div>
    <p class="small text-muted mt-3 mb-0">Accounting: Dr <em>To</em> account, Cr <em>From</em> account. No income, no GST.</p>
</div>
<div class="card-footer text-end"><a href="<?= site_url('finance/transfers') ?>" class="btn btn-outline-secondary">Cancel</a> <button type="submit" class="btn btn-primary">Record transfer</button></div>
</form>
<?= $this->endSection() ?>
