<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if ($t['status'] === 'active' && can('finance.transfer.void')): ?><button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#voidModal"><i class="bi bi-x-octagon"></i> Void</button><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php if ($t['status'] === 'void'): ?><div class="alert alert-secondary"><strong>Void</strong> on <?= dmy($t['voided_at'], true) ?>: <?= esc((string) $t['void_reason']) ?></div><?php endif ?>
<div class="u-mxw-820px card"><div class="card-body">
    <div class="row g-3 small">
        <div class="col-sm-4"><div class="text-muted">Kind</div><?= esc($kinds[$t['transfer_kind']] ?? $t['transfer_kind']) ?></div>
        <div class="col-sm-4"><div class="text-muted">Date</div><?= dmy($t['transfer_date']) ?></div>
        <div class="col-sm-4"><div class="text-muted">Amount</div><strong class="fs-5"><?= inr($t['amount']) ?></strong></div>
        <div class="col-sm-4"><div class="text-muted">From</div><a href="<?= site_url('finance/accounts/' . $t['from_account_id']) ?>"><?= esc($t['from_name']) ?></a></div>
        <div class="col-sm-4"><div class="text-muted">To</div><a href="<?= site_url('finance/accounts/' . $t['to_account_id']) ?>"><?= esc($t['to_name']) ?></a></div>
        <div class="col-sm-4"><div class="text-muted">Reference</div><span class="mono"><?= esc((string) $t['reference_no']) ?: '—' ?></span></div>
        <div class="col-sm-8"><div class="text-muted">Notes</div><?= esc((string) $t['notes']) ?: '—' ?></div>
        <div class="col-sm-4"><div class="text-muted">Recorded by</div><?= esc((string) $t['creator']) ?> · <?= dmy($t['created_at'], true) ?></div>
        <div class="col-sm-4"><div class="text-muted">Bank reconciliation</div><?= esc($t['reconciliation_status']) ?></div>
        <?php if ($journal && can('finance.ledger.view')): ?><div class="col-sm-4"><div class="text-muted">Journal</div><a class="mono" href="<?= site_url('finance/ledger/entries/' . $journal['id']) ?>"><?= esc($journal['entry_no']) ?></a></div><?php endif ?>
    </div>
</div></div>
<?php if ($t['status'] === 'active' && can('finance.transfer.void')): ?>
<div class="modal fade" id="voidModal" tabindex="-1" aria-labelledby="voidTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<form method="post" action="<?= site_url('finance/transfers/' . $t['id'] . '/void') ?>">
    <?= csrf_field() ?>
    <div class="modal-header"><h2 class="modal-title fs-5" id="voidTitle">Void <?= esc($t['transfer_number']) ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body small"><label class="form-label required" for="void_reason">Reason</label><textarea class="form-control" id="void_reason" name="reason" rows="3" required minlength="5"></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Back</button><button type="submit" class="btn btn-danger">Void transfer</button></div>
</form></div></div></div>
<?php endif ?>
<?= $this->endSection() ?>
