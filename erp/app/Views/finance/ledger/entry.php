<?= $this->extend('layouts/app') ?>
<?php [$label, $url] = journal_source($e['source_type'], $e['source_id'] === null ? null : (int) $e['source_id'], (int) $e['id']); ?>
<?= $this->section('pageActions') ?>
<?php if ($e['source_type'] === 'manual' && ! $e['reversed_by_id'] && ! $e['reversal_of_id'] && can('finance.ledger.journal')): ?>
<button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#reverseModal"><i class="bi bi-arrow-counterclockwise"></i> Reverse</button>
<?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="card mb-3"><div class="card-body small">
    <div class="row g-3">
        <div class="col-sm-3"><div class="text-muted">Date</div><?= dmy($e['entry_date']) ?></div>
        <div class="col-sm-3"><div class="text-muted">Source</div><?= $url ? '<a href="' . $url . '">' . esc($label) . '</a>' : esc($label) ?></div>
        <div class="col-sm-3"><div class="text-muted">Reference</div><span class="mono"><?= esc((string) $e['reference']) ?: '—' ?></span></div>
        <div class="col-sm-3"><div class="text-muted">Posted</div><?= esc((string) $e['poster']) ?> · <?= dmy($e['posted_at'], true) ?></div>
        <div class="col-12"><div class="text-muted">Narration</div><?= esc($e['narration']) ?></div>
        <?php if ($reversal): ?><div class="col-12"><span class="badge badge-neutral">Reversed</span> by <a class="mono" href="<?= site_url('finance/ledger/entries/' . $reversal['id']) ?>"><?= esc($reversal['entry_no']) ?></a></div><?php endif ?>
        <?php if ($original): ?><div class="col-12">Reversal of <a class="mono" href="<?= site_url('finance/ledger/entries/' . $original['id']) ?>"><?= esc($original['entry_no']) ?></a></div><?php endif ?>
    </div>
</div></div>
<div class="table-card"><div class="table-responsive">
<table class="table align-middle small mb-0">
    <thead><tr><th>Ledger</th><th>Party / account</th><th>Memo</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
    <tbody>
    <?php foreach ($lines as $l): ?>
    <tr><td><a href="<?= site_url('finance/ledger/accounts/' . $l['ledger_account_id']) ?>"><span class="mono"><?= esc($l['code']) ?></span> <?= esc($l['account_name']) ?></a></td>
        <td><?= esc((string) ($l['contact_name'] ?? $l['bank_name'] ?? '')) ?></td><td class="text-muted"><?= esc((string) $l['memo']) ?></td>
        <td class="num"><?= (float) $l['debit'] > 0 ? inr($l['debit']) : '' ?></td><td class="num"><?= (float) $l['credit'] > 0 ? inr($l['credit']) : '' ?></td></tr>
    <?php endforeach ?>
    <tr class="fw-semibold"><td colspan="3">Total</td><td class="num"><?= inr($e['total_debit']) ?></td><td class="num"><?= inr($e['total_credit']) ?></td></tr>
    </tbody>
</table>
</div></div>
<?php if ($e['source_type'] === 'manual' && ! $e['reversed_by_id'] && ! $e['reversal_of_id'] && can('finance.ledger.journal')): ?>
<div class="modal fade" id="reverseModal" tabindex="-1" aria-labelledby="revTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<form method="post" action="<?= site_url('finance/ledger/entries/' . $e['id'] . '/reverse') ?>">
    <?= csrf_field() ?>
    <div class="modal-header"><h2 class="modal-title fs-5" id="revTitle">Reverse <?= esc($e['entry_no']) ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body small"><p>A mirror entry is posted today. The original stays in the journal.</p><label class="form-label required" for="rev_reason">Reason</label><textarea class="form-control" id="rev_reason" name="reason" rows="2" required minlength="5"></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Back</button><button type="submit" class="btn btn-danger">Post reversal</button></div>
</form></div></div></div>
<?php endif ?>
<?= $this->endSection() ?>
