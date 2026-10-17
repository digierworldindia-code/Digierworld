<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if (can('finance.ledger.journal')): ?><button class="btn btn-gold btn-sm" data-bs-toggle="modal" data-bs-target="#newLedger"><i class="bi bi-plus-lg"></i> New ledger</button><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?= view('finance/ledger/_tabs', ['active' => 'finance/ledger/accounts']) ?>
<?= view('finance/ledger/_range', ['from' => $from, 'to' => $to]) ?>
<?php $cur = null; ?>
<div class="table-card"><div class="table-responsive">
<table class="table table-hover align-middle small">
    <thead><tr><th>Code</th><th>Ledger</th><th class="num">Opening</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Closing</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <?php if ($cur !== $r['account_group']): $cur = $r['account_group']; ?><tr class="table-light"><th colspan="6"><?= esc($groups[$cur]) ?></th></tr><?php endif ?>
        <tr class="<?= (int) $r['is_active'] === 1 ? '' : 'text-muted' ?>">
            <td class="mono"><?= esc($r['code']) ?></td>
            <td><a href="<?= site_url('finance/ledger/accounts/' . $r['id'] . '?' . http_build_query(['from' => $from, 'to' => $to])) ?>"><?= esc($r['name']) ?></a><?= (int) $r['is_system'] === 1 ? ' <span class="badge text-bg-light border">system</span>' : '' ?></td>
            <td class="num"><?= dr_cr($r['opening']) ?></td><td class="num"><?= (float) $r['debit'] ? inr($r['debit']) : '' ?></td><td class="num"><?= (float) $r['credit'] ? inr($r['credit']) : '' ?></td>
            <td class="num fw-semibold"><?= dr_cr($r['closing']) ?></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div></div>
<p class="small text-muted mt-2">Balances shown as Dr (positive) / Cr. Income, liability and equity ledgers normally carry Cr balances.</p>
<?php if (can('finance.ledger.journal')): ?>
<div class="modal fade" id="newLedger" tabindex="-1" aria-labelledby="nlTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<form method="post" action="<?= site_url('finance/ledger/accounts') ?>">
    <?= csrf_field() ?>
    <div class="modal-header"><h2 class="modal-title fs-5" id="nlTitle">New ledger</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><label class="form-label required" for="nl_name">Name</label><input class="form-control mb-2" id="nl_name" name="name" required maxlength="150">
        <label class="form-label required" for="nl_group">Group</label><select class="form-select" id="nl_group" name="account_group"><?php foreach ($groups as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach ?></select>
        <div class="form-text">Bank and cash accounts are added in Settings → Bank &amp; Payment Profiles so they get their own statement.</div></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-dark">Create</button></div>
</form></div></div></div>
<?php endif ?>
<?= $this->endSection() ?>
